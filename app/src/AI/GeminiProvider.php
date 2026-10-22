<?php
declare(strict_types=1);

namespace LQ\AI;

use Gemini\Client;
use Gemini\Data\Content;
use Gemini\Data\FunctionCallingConfig;
use Gemini\Data\FunctionDeclaration;
use Gemini\Data\FunctionResponse;
use Gemini\Data\GenerationConfig;
use Gemini\Data\Part;
use Gemini\Data\Tool;
use Gemini\Data\ToolConfig;
use Gemini\Enums\FinishReason;
use Gemini\Enums\Mode;
use Gemini\Enums\ResponseMimeType;
use Gemini\Enums\Role;
use Gemini\Responses\GenerativeModel\GenerateContentResponse;
use Gemini\Exceptions\ErrorException;
use Gemini\Exceptions\TransporterException;
use Gemini\Exceptions\UnserializableResponse;

final class GeminiProvider implements Provider
{
    public function __construct(private string $apiKey, private string $model)
    {
        Connectors::load('gemini');
    }

    private function client(): Client
    {
        return \Gemini::client($this->apiKey);
    }

    public function complete(string $system, string $user, int $maxTokens, ?array $schema = null, string $effort = 'low'): string
    {
        $model = $this->client()->generativeModel(model: $this->model)
            ->withSystemInstruction(Content::parse($system))
            ->withGenerationConfig($this->config($maxTokens, $schema));
        return self::text($this->send(fn () => $model->generateContent($user)));
    }

    public function completeWithTools(string $system, string $user, array $tools, callable $run, int $maxTokens, array $schema, string $effort = 'medium', int $maxRounds = 8): string
    {
        return $this->converse($system, [['role' => 'user', 'text' => $user]], $tools, $run, $maxTokens, $schema, $effort, $maxRounds);
    }

    public function converse(string $system, array $history, array $tools, callable $run, int $maxTokens, ?array $schema = null, string $effort = 'medium', int $maxRounds = 8): string
    {
        $tool = new Tool(functionDeclarations: array_map(
            fn ($t) => new FunctionDeclaration(name: $t['name'], description: $t['description'], parametersJsonSchema: $t['parameters']),
            $tools,
        ));
        $contents = array_map(fn ($m) => new Content(parts: [new Part(text: $m['text'])], role: $m['role'] === 'assistant' ? Role::MODEL : Role::USER), $history);
        for ($round = 0; $round <= $maxRounds; $round++) {
            $model = $this->client()->generativeModel(model: $this->model)
                ->withSystemInstruction(Content::parse($system))
                ->withGenerationConfig($this->config($maxTokens, $schema))
                ->withTool($tool)
                ->withToolConfig(new ToolConfig(functionCallingConfig: new FunctionCallingConfig(mode: $round === $maxRounds ? Mode::NONE : Mode::AUTO)));
            $r = $this->send(fn () => $model->generateContent(...$contents));
            $content = $r->candidates[0]->content ?? null;
            $calls = array_values(array_filter($content?->parts ?? [], fn (Part $p) => $p->functionCall !== null));
            if (!$calls) {
                return self::text($r);
            }
            // Gửi lại nguyên lượt của model (có thoughtSignature) — Gemini 3 bắt buộc khi gọi hàm nhiều lượt.
            $contents[] = $content;
            $contents[] = new Content(parts: array_map(fn (Part $p) => new Part(functionResponse: new FunctionResponse(
                name: $p->functionCall->name,
                response: ['result' => $run($p->functionCall->name, $p->functionCall->args)],
                id: $p->functionCall->id,
            )), $calls), role: Role::USER);
        }
        throw new AIException('Gemini tra cứu quá nhiều lượt mà chưa trả lời — thử lại với yêu cầu cụ thể hơn.');
    }

    private function config(int $maxTokens, ?array $schema): GenerationConfig
    {
        // Gemini 3.x suy nghĩ trước khi trả lời và phần suy nghĩ tính chung vào maxOutputTokens —
        // chừa thêm 8192 token, không thì câu trả lời bị cụt giữa chừng (vd. "Chào bạn! Tôi là").
        $limit = $maxTokens + 8192;
        return $schema
            ? new GenerationConfig(maxOutputTokens: $limit, responseMimeType: ResponseMimeType::APPLICATION_JSON, responseJsonSchema: $schema)
            : new GenerationConfig(maxOutputTokens: $limit);
    }

    private function send(callable $call): GenerateContentResponse
    {
        try {
            // UnserializableResponse: máy chủ trả trang không phải JSON (thường là trang lỗi HTML 502/503 của Google khi quá tải) — tạm thời, thử lại.
            return Retry::run($call, fn (\Throwable $e) => $e instanceof TransporterException || $e instanceof UnserializableResponse || ($e instanceof ErrorException && self::transient($e)));
        } catch (ErrorException $e) {
            throw new AIException(self::message($e));
        } catch (TransporterException) {
            throw new AIException('Không kết nối được tới Gemini (kiểm tra mạng của máy chủ).');
        } catch (UnserializableResponse) {
            throw new AIException('Máy chủ Gemini trả về dữ liệu không đọc được (đã thử 3 lần) — thường do quá tải tạm thời, thử lại sau ít phút.');
        }
    }

    /** Ghép các phần chữ của câu trả lời (bỏ phần suy nghĩ). */
    private static function text(GenerateContentResponse $r): string
    {
        $c = $r->candidates[0] ?? null;
        if (($c->finishReason ?? null) === FinishReason::MAX_TOKENS) {
            throw new AIException('Câu trả lời quá dài, bị cắt giữa chừng — thử với đoạn ngắn hơn.');
        }
        $text = '';
        foreach ($c?->content?->parts ?? [] as $p) {
            if ($p->text !== null && !$p->thought) {
                $text .= $p->text;
            }
        }
        if (trim($text) === '') {
            throw new AIException('Gemini không trả về nội dung (có thể bị bộ lọc an toàn chặn).');
        }
        return $text;
    }

    public function models(): array
    {
        // Chỉ giữ model viết chữ: bỏ model tạo nhạc (Lyria), ảnh (Nano Banana), robot, chép lời, tác tử nghiên cứu/lập trình.
        $out = [];
        $token = null;
        try {
            // Đi hết các trang (mỗi trang tối đa 1000) — trang đầu chưa chắc đã có model mới nhất.
            for ($page = 0; $page < 5; $page++) {
                $list = $this->client()->models()->list(pageSize: 1000, nextPageToken: $token);
                foreach ($list->models as $m) {
                    if (in_array('generateContent', $m->supportedGenerationMethods, true) && !preg_match('/(embedding|tts|image|aqa|lyria|banana|robotics|transcribe|deep-research|antigravity|computer-use)/', $m->name)) {
                        $out[] = ['id' => preg_replace('#^models/#', '', $m->name), 'name' => $m->displayName];
                    }
                }
                if (!($token = $list->nextPageToken)) {
                    break;
                }
            }
        } catch (ErrorException $e) {
            throw new AIException(self::message($e));
        } catch (TransporterException) {
            throw new AIException('Không kết nối được tới Gemini (kiểm tra mạng của máy chủ).');
        } catch (UnserializableResponse) {
            throw new AIException('Máy chủ Gemini trả về dữ liệu không đọc được — thử lại sau ít phút.');
        }
        // Model mới (số phiên bản lớn) lên đầu: gemini-3.5-… trước gemini-2.5-…
        usort($out, fn ($a, $b) => strnatcmp($b['id'], $a['id']));
        return $out;
    }

    /** Lỗi tạm thời đáng thử lại: quá tải / máy chủ lỗi (5xx) hoặc vượt tần suất (429). */
    private static function transient(ErrorException $e): bool
    {
        return in_array($e->getErrorCode(), [429, 500, 502, 503, 504], true)
            || in_array($e->getErrorStatus(), ['UNAVAILABLE', 'RESOURCE_EXHAUSTED', 'INTERNAL', 'DEADLINE_EXCEEDED'], true);
    }

    private static function message(ErrorException $e): string
    {
        $code = $e->getErrorCode();
        return match (true) {
            (bool) preg_match('/api.?key/i', $e->getMessage()) => 'API key của Gemini không hợp lệ hoặc đã bị thu hồi.',
            $code === 503 || $e->getErrorStatus() === 'UNAVAILABLE' => 'Model Gemini đang quá tải phía Google (đã thử 3 lần). Thường chỉ tạm thời — thử lại sau ít phút, hoặc chọn model khác trong Cài đặt › AI.',
            $code === 429 || $e->getErrorStatus() === 'RESOURCE_EXHAUSTED' => 'Gemini báo vượt giới hạn tần suất hoặc hết hạn mức của key. Chờ một lúc, hoặc kiểm tra hạn mức trong Google AI Studio.',
            $code >= 500 => 'Máy chủ Gemini đang gặp sự cố (đã thử 3 lần) — thử lại sau.',
            $code === 404 => 'Model Gemini này không tồn tại hoặc đã ngừng cung cấp — chọn model khác trong Cài đặt › AI.',
            default => 'Gemini báo lỗi: ' . $e->getMessage(),
        };
    }
}
