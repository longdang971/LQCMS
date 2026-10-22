<?php
declare(strict_types=1);

namespace LQ\AI;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\NotFoundException;
use Anthropic\Core\Exceptions\RateLimitException;

final class AnthropicProvider implements Provider
{
    /** Model có hỗ trợ fallbacks: "default" (chạy lại trên model khác khi bị bộ lọc an toàn từ chối). */
    private const FALLBACK_MODELS = ['claude-fable-5-1', 'claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5-5'];

    public function __construct(private string $apiKey, private string $model)
    {
        Connectors::load('anthropic');
    }

    private function client(): Client
    {
        return new Client(apiKey: $this->apiKey);
    }

    public function complete(string $system, string $user, int $maxTokens, ?array $schema = null, string $effort = 'low'): string
    {
        return $this->text($this->send($system, [['role' => 'user', 'content' => $user]], $maxTokens, $schema, $effort));
    }

    public function completeWithTools(string $system, string $user, array $tools, callable $run, int $maxTokens, array $schema, string $effort = 'medium', int $maxRounds = 8): string
    {
        return $this->converse($system, [['role' => 'user', 'text' => $user]], $tools, $run, $maxTokens, $schema, $effort, $maxRounds);
    }

    public function converse(string $system, array $history, array $tools, callable $run, int $maxTokens, ?array $schema = null, string $effort = 'medium', int $maxRounds = 8): string
    {
        $defs = array_map(fn ($t) => ['name' => $t['name'], 'description' => $t['description'], 'inputSchema' => $t['parameters']], $tools);
        $messages = array_map(fn ($m) => ['role' => $m['role'], 'content' => $m['text']], $history);
        for ($round = 0; $round <= $maxRounds; $round++) {
            // Lượt cuối: vẫn khai báo công cụ (lịch sử có tool_use) nhưng không cho gọi nữa.
            $m = $this->send($system, $messages, $maxTokens, $schema, $effort, $defs, $round === $maxRounds ? ['type' => 'none'] : null);
            if ($m->stopReason !== 'tool_use') {
                return $this->text($m);
            }
            $results = [];
            foreach ($m->content as $block) {
                if ($block->type === 'tool_use') {
                    $results[] = ['type' => 'tool_result', 'toolUseID' => $block->id, 'content' => $run($block->name, (array) $block->input)];
                }
            }
            // Trả nguyên khối của model (kể cả thinking) để lượt sau còn mạch suy nghĩ.
            $messages[] = ['role' => 'assistant', 'content' => $m->content];
            $messages[] = ['role' => 'user', 'content' => $results];
        }
        throw new AIException('Claude tra cứu quá nhiều lượt mà chưa trả lời — thử lại với yêu cầu cụ thể hơn.');
    }

    private function send(string $system, array $messages, int $maxTokens, ?array $schema, string $effort, ?array $tools = null, ?array $toolChoice = null): object
    {
        $outputConfig = ['effort' => $effort];
        if ($schema) {
            $outputConfig['format'] = ['type' => 'json_schema', 'schema' => $schema];
        }
        $fallback = in_array($this->model, self::FALLBACK_MODELS, true);
        try {
            return $this->client()->beta->messages->create(
                model: $this->model,
                maxTokens: $maxTokens,
                system: $system,
                messages: $messages,
                outputConfig: $outputConfig,
                tools: $tools,
                toolChoice: $toolChoice,
                fallbacks: $fallback ? 'default' : null,
                betas: $fallback ? ['server-side-fallback-2026-07-01'] : null,
            );
        } catch (AuthenticationException) {
            throw new AIException('API key của Claude không hợp lệ hoặc đã bị thu hồi.');
        } catch (NotFoundException) {
            throw new AIException("Không tìm thấy model \"{$this->model}\" — chọn model khác trong Cài đặt › AI.");
        } catch (RateLimitException) {
            throw new AIException('Claude đang giới hạn tần suất gọi — thử lại sau ít phút.');
        } catch (APIStatusException $e) {
            throw new AIException('Claude báo lỗi: ' . $e->getMessage());
        } catch (APIConnectionException) {
            throw new AIException('Không kết nối được tới Claude (kiểm tra mạng của máy chủ).');
        }
    }

    private function text(object $message): string
    {
        // Kiểm tra stop_reason trước khi đọc nội dung: bị từ chối thì content có thể rỗng hoặc dở dang.
        if ($message->stopReason === 'refusal') {
            throw new AIException('Claude từ chối xử lý yêu cầu này.');
        }
        if ($message->stopReason === 'max_tokens') {
            throw new AIException('Câu trả lời quá dài, bị cắt giữa chừng — thử với đoạn ngắn hơn.');
        }
        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }
        return $text;
    }

    public function models(): array
    {
        try {
            $page = $this->client()->models->list(limit: 100);
        } catch (AuthenticationException) {
            throw new AIException('API key của Claude không hợp lệ hoặc đã bị thu hồi.');
        } catch (APIStatusException | APIConnectionException $e) {
            throw new AIException('Không lấy được danh sách model: ' . $e->getMessage());
        }
        // API trả model mới nhất trước — giữ nguyên thứ tự.
        return array_map(fn ($m) => ['id' => $m->id, 'name' => $m->displayName], $page->getItems());
    }
}
