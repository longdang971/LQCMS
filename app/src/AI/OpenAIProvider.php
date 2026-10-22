<?php
declare(strict_types=1);

namespace LQ\AI;

use OpenAI\Client;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\RateLimitException;
use OpenAI\Exceptions\ServerException;
use OpenAI\Exceptions\TransporterException;

final class OpenAIProvider implements Provider
{
    public function __construct(private string $apiKey, private string $model)
    {
        Connectors::load('openai');
    }

    private function client(): Client
    {
        return \OpenAI::client($this->apiKey);
    }

    public function complete(string $system, string $user, int $maxTokens, ?array $schema = null, string $effort = 'low'): string
    {
        $params = [
            'model' => $this->model,
            'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
            // Model mới của OpenAI dùng max_completion_tokens thay cho max_tokens.
            'max_completion_tokens' => $maxTokens,
        ];
        if ($schema) {
            $params['response_format'] = self::format($schema);
        }
        return self::content($this->send($params));
    }

    public function completeWithTools(string $system, string $user, array $tools, callable $run, int $maxTokens, array $schema, string $effort = 'medium', int $maxRounds = 8): string
    {
        return $this->converse($system, [['role' => 'user', 'text' => $user]], $tools, $run, $maxTokens, $schema, $effort, $maxRounds);
    }

    public function converse(string $system, array $history, array $tools, callable $run, int $maxTokens, ?array $schema = null, string $effort = 'medium', int $maxRounds = 8): string
    {
        $defs = array_map(fn ($t) => ['type' => 'function', 'function' => ['name' => $t['name'], 'description' => $t['description'], 'parameters' => $t['parameters']]], $tools);
        $messages = [['role' => 'system', 'content' => $system], ...array_map(fn ($m) => ['role' => $m['role'], 'content' => $m['text']], $history)];
        for ($round = 0; $round <= $maxRounds; $round++) {
            $r = $this->send([
                'model' => $this->model,
                'messages' => $messages,
                'max_completion_tokens' => $maxTokens,
                'tools' => $defs,
                'tool_choice' => $round === $maxRounds ? 'none' : 'auto',
            ] + ($schema ? ['response_format' => self::format($schema)] : []));
            $msg = $r->choices[0]->message ?? null;
            if (!$msg || !$msg->toolCalls) {
                return self::content($r);
            }
            $messages[] = ['role' => 'assistant', 'content' => $msg->content, 'tool_calls' => array_map(fn ($c) => [
                'id' => $c->id, 'type' => 'function', 'function' => ['name' => $c->function->name, 'arguments' => $c->function->arguments],
            ], $msg->toolCalls)];
            foreach ($msg->toolCalls as $c) {
                $args = json_decode($c->function->arguments, true);
                $messages[] = ['role' => 'tool', 'tool_call_id' => $c->id, 'content' => $run($c->function->name, is_array($args) ? $args : [])];
            }
        }
        throw new AIException('ChatGPT tra cứu quá nhiều lượt mà chưa trả lời — thử lại với yêu cầu cụ thể hơn.');
    }

    private static function format(array $schema): array
    {
        return ['type' => 'json_schema', 'json_schema' => ['name' => 'result', 'strict' => true, 'schema' => $schema]];
    }

    private function send(array $params): object
    {
        try {
            return Retry::run(
                fn () => $this->client()->chat()->create($params),
                fn (\Throwable $e) => $e instanceof RateLimitException || $e instanceof ServerException || $e instanceof TransporterException,
            );
        } catch (RateLimitException) {
            throw new AIException('ChatGPT báo vượt giới hạn tần suất hoặc hết hạn mức của key (đã thử 3 lần). Chờ một lúc, hoặc kiểm tra hạn mức trong trang OpenAI.');
        } catch (ServerException) {
            throw new AIException('Máy chủ ChatGPT đang quá tải hoặc gặp sự cố (đã thử 3 lần) — thử lại sau ít phút.');
        } catch (ErrorException $e) {
            throw new AIException(self::message($e));
        } catch (TransporterException) {
            throw new AIException('Không kết nối được tới ChatGPT (kiểm tra mạng của máy chủ).');
        }
    }

    private static function content(object $r): string
    {
        $choice = $r->choices[0] ?? null;
        if ($choice === null || $choice->message->content === null) {
            throw new AIException('ChatGPT không trả về nội dung' . ($choice?->finishReason ? " ({$choice->finishReason})." : '.'));
        }
        if ($choice->finishReason === 'length') {
            throw new AIException('Câu trả lời quá dài, bị cắt giữa chừng — thử với đoạn ngắn hơn.');
        }
        return $choice->message->content;
    }

    public function models(): array
    {
        try {
            $list = $this->client()->models()->list();
        } catch (ErrorException $e) {
            throw new AIException(self::message($e));
        } catch (TransporterException) {
            throw new AIException('Không kết nối được tới ChatGPT (kiểm tra mạng của máy chủ).');
        }
        // Danh sách của OpenAI gồm cả model ảnh/âm thanh/nhúng — chỉ giữ model trò chuyện.
        $ids = array_filter(array_map(fn ($m) => $m->id, $list->data),
            fn ($id) => preg_match('/^(gpt|o\d|chatgpt)/', $id) && !preg_match('/(audio|realtime|transcribe|tts|image|search|embedding)/', $id));
        usort($ids, fn ($a, $b) => strnatcmp($b, $a));
        return array_map(fn ($id) => ['id' => $id, 'name' => $id], array_values($ids));
    }

    private static function message(ErrorException $e): string
    {
        return preg_match('/api.?key/i', $e->getMessage())
            ? 'API key của ChatGPT không hợp lệ hoặc đã bị thu hồi.'
            : 'ChatGPT báo lỗi: ' . $e->getMessage();
    }
}
