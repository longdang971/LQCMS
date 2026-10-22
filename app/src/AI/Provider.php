<?php
declare(strict_types=1);

namespace LQ\AI;

/** Một nhà cung cấp AI. Mọi tính năng chỉ gọi qua giao diện này nên không cần biết đang dùng Claude, ChatGPT hay Gemini. */
interface Provider
{
    /**
     * Một lượt hỏi-đáp. $schema (JSON Schema dạng object) có thì yêu cầu trả JSON đúng khuôn và trả về chuỗi JSON.
     * $effort: 'low' cho việc nhanh (sửa đoạn văn), 'medium' cho việc cần suy nghĩ (tìm lỗi theme).
     * @throws AIException
     */
    public function complete(string $system, string $user, int $maxTokens, ?array $schema = null, string $effort = 'low'): string;

    /**
     * Như complete() nhưng model được gọi công cụ nhiều lượt (vd. đọc mã nguồn — xem CodeTools) rồi mới trả JSON theo $schema.
     * $tools: list<array{name, description, parameters}> (parameters là JSON Schema); $run(string $name, array $args): string là kết quả trả lại cho model.
     * Quá $maxRounds lượt gọi công cụ thì lượt cuối cấm gọi để buộc model trả lời.
     * @throws AIException
     */
    public function completeWithTools(string $system, string $user, array $tools, callable $run, int $maxTokens, array $schema, string $effort = 'medium', int $maxRounds = 8): string;

    /**
     * Hội thoại nhiều lượt có công cụ. $history: list<array{role: 'user'|'assistant', text: string}> (đã xếp xen kẽ, bắt đầu bằng user).
     * $schema null = trả lời văn bản tự do.
     * @throws AIException
     */
    public function converse(string $system, array $history, array $tools, callable $run, int $maxTokens, ?array $schema = null, string $effort = 'medium', int $maxRounds = 8): string;

    /** @return list<array{id:string, name:string}> model dùng được với key này */
    public function models(): array;
}
