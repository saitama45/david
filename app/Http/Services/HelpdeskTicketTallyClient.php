<?php

namespace App\Http\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pulls the weekly helpdesk ticket tally from ghelpdesk
 * (GET /api/integrations/david/ticket-tally).
 *
 * ghelpdesk owns the counting rules: incoming = tickets created that week on an
 * item tagged `david.{module}`, closed = those same tickets now "closed", scoped
 * to the entity whose code matches ours. This class only fetches, reshapes the
 * payload into SuccessRateWeeklyTicket column names and caches it briefly.
 *
 * Any failure returns null so the Success Rate tab falls back to the last saved
 * counts instead of breaking.
 */
class HelpdeskTicketTallyClient
{
    private const CACHE_MINUTES = 5;

    private ?string $lastError = null;

    public function isConfigured(): bool
    {
        return filled(config('services.helpdesk.url')) && filled(config('services.helpdesk.key'));
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * @return array<string, array<string, int>>|null  week_start => [order_incoming => n, ...]
     */
    public function weeklyCounts(string $entityCode, Carbon $dateFrom, Carbon $dateTo, bool $refresh = false): ?array
    {
        $this->lastError = null;

        if (! $this->isConfigured()) {
            return null;
        }

        $cacheKey = 'helpdesk_ticket_tally_v1_'.md5(json_encode([
            strtoupper($entityCode), $dateFrom->toDateString(), $dateTo->toDateString(),
        ]));

        if (! $refresh && is_array($cached = Cache::get($cacheKey))) {
            return $cached;
        }

        try {
            $response = Http::acceptJson()
                ->timeout(config('services.helpdesk.timeout', 10))
                ->withHeaders(['X-Integration-Key' => trim((string) config('services.helpdesk.key'))])
                ->get(rtrim(trim((string) config('services.helpdesk.url')), '/').'/api/integrations/david/ticket-tally', [
                    'entity' => $entityCode,
                    'date_from' => $dateFrom->toDateString(),
                    'date_to' => $dateTo->toDateString(),
                ]);
        } catch (\Throwable $e) {
            return $this->fail('Helpdesk is unreachable.', ['exception' => $e->getMessage()]);
        }

        if (! $response->successful() || ! is_array($response->json('weeks'))) {
            $message = $response->json('message') ?: "Helpdesk returned HTTP {$response->status()}.";

            // On a key mismatch Helpdesk returns short fingerprints of both keys,
            // so an admin can tell which app holds the wrong value.
            if ($response->json('helpdesk_key_fingerprint')) {
                $message .= ' Helpdesk key: '.$response->json('helpdesk_key_fingerprint')
                    .'; DAVID sent: '.($response->json('received_key_fingerprint') ?? 'nothing')
                    .' (request to '.parse_url((string) config('services.helpdesk.url'), PHP_URL_HOST).')';
            }

            return $this->fail($message, ['status' => $response->status()]);
        }

        $counts = [];

        foreach ($response->json('weeks') as $week) {
            $row = [];

            foreach (($week['modules'] ?? []) as $module => $values) {
                $row[$module.'_incoming'] = max(0, (int) ($values['incoming'] ?? 0));
                $row[$module.'_closed'] = max(0, (int) ($values['closed'] ?? 0));
            }

            if (! empty($week['week_start'])) {
                $counts[$week['week_start']] = $row;
            }
        }

        Cache::put($cacheKey, $counts, now()->addMinutes(self::CACHE_MINUTES));

        return $counts;
    }

    private function fail(string $message, array $context): null
    {
        $this->lastError = $message;
        Log::warning('Helpdesk ticket tally fetch failed: '.$message, $context);

        return null;
    }
}
