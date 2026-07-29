<?php

namespace App\Services\TradingV2\DTOs;

/**
 * Holds one alert version's metadata and its active gate thresholds.
 *
 * Loaded from the DB (alert_versions + alert_version_gates tables),
 * cached in Redis (rt:config:tradingv2:versions).
 */
class AlertVersionConfig
{
    /**
     * @param  array<string, mixed>  $version  Row from alert_versions
     * @param  array<string, array{threshold: float|null, op: string}>  $gates5m
     * @param  array<string, array{threshold: float|null, op: string}>  $gates1m
     */
    public function __construct(
        public readonly array $version,
        public readonly array $gates5m,
        public readonly array $gates1m,
    ) {}

    public function id(): int
    {
        return (int) $this->version['id'];
    }

    public function pipelineLetter(): string
    {
        return (string) $this->version['pipeline_letter'];
    }

    public function versionString(): string
    {
        return (string) $this->version['version_string'];
    }

    public function signalType(): string
    {
        return (string) $this->version['signal_type'];
    }

    public function scoreFormula(): ?string
    {
        return $this->version['scanner_score_formula'] ?? null;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id(),
            'pipeline_letter' => $this->pipelineLetter(),
            'version_string' => $this->versionString(),
            'signal_type' => $this->signalType(),
            'scanner_score_formula' => $this->scoreFormula(),
            'gates_5m' => $this->gates5m,
            'gates_1m' => $this->gates1m,
        ];
    }
}
