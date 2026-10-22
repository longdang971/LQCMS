<?php
declare(strict_types=1);

namespace LQ\AI;

/**
 * Cấu hình AI của site (một nhà cung cấp đang dùng + key + model cho từng nhà cung cấp, admin nhập ở Cài đặt › AI).
 * Mọi nút/khung AI trong admin chỉ hiện khi enabled(): đã cài thư viện, có key và đã chọn model.
 */
final class AI
{
    public static function active(): string
    {
        $p = (string) option('ai_provider', '');
        return isset(Connectors::LIST[$p]) ? $p : '';
    }

    public static function key(string $id): string
    {
        return Secrets::decrypt((string) option("ai_{$id}_key", ''));
    }

    public static function model(string $id): string
    {
        return (string) option("ai_{$id}_model", '');
    }

    /** Nhà cung cấp đủ điều kiện dùng: đã cài, có key, có model. */
    public static function ready(string $id): bool
    {
        return Connectors::installed($id) && self::key($id) !== '' && self::model($id) !== '';
    }

    public static function enabled(): bool
    {
        static $on = null;
        return $on ??= ($id = self::active()) !== '' && self::ready($id);
    }

    public static function provider(?string $id = null, ?string $key = null, ?string $model = null): Provider
    {
        $id ??= self::active();
        $key ??= self::key($id);
        $model ??= self::model($id);
        return match ($id) {
            'anthropic' => new AnthropicProvider($key, $model),
            'openai' => new OpenAIProvider($key, $model),
            'gemini' => new GeminiProvider($key, $model),
            default => throw new AIException('Chưa chọn nhà cung cấp AI.'),
        };
    }

    /**
     * Như json() nhưng AI được tự tra mã nguồn của site (CodeTools: chỉ đọc) trước khi trả lời.
     * $tools->log cho biết AI đã đọc/tìm gì sau khi gọi xong. @throws AIException
     */
    public static function jsonWithCode(string $system, string $user, array $schema, CodeTools $tools, int $maxTokens = 16000, string $effort = 'medium', int $maxRounds = 8): array
    {
        // Nhiều lượt gọi nối tiếp: nới giới hạn thời gian chạy của PHP (mặc định 30 giây trên nhiều máy chủ).
        @set_time_limit(240);
        try {
            $text = self::provider()->completeWithTools(CodeTools::guide() . $tools->mountGuide() . $system, $user, CodeTools::definitions(), $tools->run(...), $maxTokens, $schema, $effort, $maxRounds);
        } catch (AIException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Lỗi ngoài dự kiến trong SDK (vd. dữ liệu không mã hoá được) — báo lỗi gọn thay vì trang lỗi PHP.
            error_log('[LQCMS AI] ' . $e::class . ': ' . $e->getMessage());
            throw new AIException('Trợ lý AI gặp lỗi khi xử lý: ' . $e->getMessage());
        }
        return self::decode($text);
    }

    /**
     * Nhà cung cấp dùng được (đã cài thư viện + có key) kèm danh sách model — cho ô chọn model trong khung chat.
     * Danh sách model lấy từ API nhà cung cấp, lưu tạm 12 giờ trong option ai_{id}_models_cache. @return list<array>
     */
    public static function choices(bool $refresh = false): array
    {
        $out = [];
        foreach (Connectors::LIST as $id => $info) {
            if (!Connectors::installed($id) || self::key($id) === '') {
                continue;
            }
            $cache = (array) option("ai_{$id}_models_cache", []);
            $models = $cache['models'] ?? null;
            $error = null;
            if ($refresh || !is_array($models) || time() - (int) ($cache['at'] ?? 0) > 12 * 3600) {
                try {
                    $models = self::provider($id, null, 'x')->models();
                    update_option("ai_{$id}_models_cache", ['at' => time(), 'models' => $models], false);
                } catch (AIException $e) {
                    $error = $e->getMessage();
                    $models = is_array($models) ? $models : [];
                }
            }
            $saved = self::model($id);
            if ($saved !== '' && !in_array($saved, array_column($models, 'id'), true)) {
                array_unshift($models, ['id' => $saved, 'name' => $saved]);
            }
            $out[] = ['id' => $id, 'label' => $info['label'], 'default_model' => $saved, 'active' => $id === self::active(), 'models' => array_values($models), 'error' => $error];
        }
        return $out;
    }

    /** Model người dùng chọn có hợp lệ không: nhà cung cấp dùng được + model nằm trong danh sách đã biết (hoặc là model đã lưu). */
    public static function allowedModel(string $id, string $model): bool
    {
        if (!isset(Connectors::LIST[$id]) || !Connectors::installed($id) || self::key($id) === '' || !preg_match('/^[A-Za-z0-9._:\/-]{1,120}$/', $model)) {
            return false;
        }
        $cached = array_column((array) (option("ai_{$id}_models_cache", [])['models'] ?? []), 'id');
        return $model === self::model($id) || in_array($model, $cached, true);
    }

    /**
     * Một lượt chat (trả văn bản) — AI tự dùng công cụ của AgentTools.
     * $provider/$model: lựa chọn riêng trong khung chat; null = nhà cung cấp và model mặc định của site. @throws AIException
     */
    public static function chat(string $system, array $history, AgentTools $tools, ?string $provider = null, ?string $model = null): string
    {
        try {
            return self::provider($provider, null, $model ?: null)->converse($system, $history, AgentTools::definitions(), $tools->run(...), 8000, null, 'medium', 12);
        } catch (AIException $e) {
            throw $e;
        } catch (\Throwable $e) {
            error_log('[LQCMS AI chat] ' . $e::class . ': ' . $e->getMessage());
            throw new AIException('Trợ lý AI gặp lỗi khi xử lý: ' . $e->getMessage());
        }
    }

    /** Gọi AI và giải mã JSON theo $schema. @throws AIException */
    public static function json(string $system, string $user, array $schema, int $maxTokens = 4000, string $effort = 'low'): array
    {
        try {
            $text = self::provider()->complete($system, $user, $maxTokens, $schema, $effort);
        } catch (AIException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Như jsonWithCode/chat: lỗi ngoài dự kiến của SDK (vd. Gemini trả trang HTML 502 → UnserializableResponse)
            // phải thành AIException — nơi gọi chỉ bắt AIException, lọt ra là trang lỗi 500 thay vì JSON.
            error_log('[LQCMS AI] ' . $e::class . ': ' . $e->getMessage());
            throw new AIException('Trợ lý AI gặp lỗi khi xử lý: ' . $e->getMessage());
        }
        return self::decode($text);
    }

    private static function decode(string $text): array
    {
        $text = trim($text);
        // Phòng model bọc JSON trong ```json … ``` dù đã yêu cầu định dạng.
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $text);
        $data = json_decode($text, true);
        // Model kèm lời dẫn quanh JSON ("Đây là kết quả: ```json {…} ```"): lấy khối {…} ngoài cùng.
        if (!is_array($data) && ($a = strpos($text, '{')) !== false && ($b = strrpos($text, '}')) > $a) {
            $data = json_decode(substr($text, $a, $b - $a + 1), true);
        }
        if (!is_array($data)) {
            throw new AIException('AI trả về dữ liệu không đúng định dạng, thử lại.');
        }
        return $data;
    }
}
