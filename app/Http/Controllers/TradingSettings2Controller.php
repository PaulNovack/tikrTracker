<?php

namespace App\Http\Controllers;

use App\Services\TradingSettingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class TradingSettings2Controller extends Controller
{
    /** @var list<string> */
    private const PIPELINES = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's', 'x', 'external', 'manual'];

    /**
     * Show the trade settings 2 page.
     */
    public function edit(): Response
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        return Inertia::render('trading-settings-2/index', [
            'isPaperTrading' => (bool) config('alpaca.paper_trading', true),
            'credentials' => [
                'PROD_ALPACA_KEY_ID' => TradingSettingService::get('trading2.credentials.PROD_ALPACA_KEY_ID', ''),
                'PROD_ALPACA_SECRET_KEY' => TradingSettingService::get('trading2.credentials.PROD_ALPACA_SECRET_KEY', ''),
                'PAPER_ALPACA_KEY_ID' => TradingSettingService::get('trading2.credentials.PAPER_ALPACA_KEY_ID', ''),
                'PAPER_ALPACA_SECRET_KEY' => TradingSettingService::get('trading2.credentials.PAPER_ALPACA_SECRET_KEY', ''),
                'ALPACA_API_KEY' => TradingSettingService::get('trading2.credentials.ALPACA_API_KEY', ''),
                'ALPACA_API_SECRET' => TradingSettingService::get('trading2.credentials.ALPACA_API_SECRET', ''),
                'ALPACA_PAPER_TRADING' => TradingSettingService::get('trading2.credentials.ALPACA_PAPER_TRADING', 'true'),
                'MAIL_USERNAME' => TradingSettingService::get('trading2.credentials.MAIL_USERNAME', ''),
                'MAIL_PASSWORD' => TradingSettingService::get('trading2.credentials.MAIL_PASSWORD', ''),
                'OPENAI_API_KEY' => TradingSettingService::get('trading2.credentials.OPENAI_API_KEY', ''),
            ],
            'scorerScripts' => collect(self::PIPELINES)->mapWithKeys(fn ($p) => [
                $p => TradingSettingService::get(
                    'trading2.scorer_script.'.strtolower($p),
                    config("trading.ml_scoring.pipeline_{$p}_scorer_script", 'python_ml/v2/score_single_alert_v2.py')
                ),
            ])->all(),
            'modelPaths' => collect(self::PIPELINES)->mapWithKeys(fn ($p) => [
                $p => config("trading.ml_scoring.pipeline_{$p}_model_path", ''),
            ])->all(),
            'pipelineDisplayNames' => collect(self::PIPELINES)->mapWithKeys(fn ($p) => [
                $p => config("trading.pipeline_display_names.{$p}", strtoupper($p)),
            ])->all(),
            'threeWhiteSoldiersScanEnabled' => TradingSettingService::isThreeWhiteSoldiersScanEnabled(),
        ]);
    }

    /**
     * Update other / misc settings.
     */
    public function updateOther(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $validated = $request->validate([
            'three_white_soldiers_scan_enabled' => ['boolean'],
        ]);

        TradingSettingService::set(
            'trading.scanner.three_white_soldiers_enabled',
            $validated['three_white_soldiers_scan_enabled'] ? '1' : '0'
        );

        Log::info('[TradingSettings2] Other scanner settings updated by '.auth()->user()?->email, [
            'three_white_soldiers_scan_enabled' => $validated['three_white_soldiers_scan_enabled'],
        ]);

        return back()->with('status', 'other-updated');
    }

    /**
     * Update credentials.
     */
    public function update(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        // TEMPORARY DEBUG: dump what's arriving
        $debug = [
            'all' => $request->all(),
            'has_model_paths' => $request->has('model_paths'),
            'has_scorer_scripts' => $request->has('scorer_scripts'),
        ];
        \Illuminate\Support\Facades\Log::error('[TradingSettings2 DEBUG] '.json_encode($debug));

        // Determine which section we're updating
        if ($request->has('scorer_scripts')) {
            return $this->updateScorerScripts($request);
        }

        if ($request->has('model_paths')) {
            return $this->updateModelPaths($request);
        }

        return $this->updateCredentials($request);
    }

    private function updateCredentials(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'PROD_ALPACA_KEY_ID' => ['nullable', 'string', 'max:255'],
            'PROD_ALPACA_SECRET_KEY' => ['nullable', 'string', 'max:255'],
            'PAPER_ALPACA_KEY_ID' => ['nullable', 'string', 'max:255'],
            'PAPER_ALPACA_SECRET_KEY' => ['nullable', 'string', 'max:255'],
            'ALPACA_API_KEY' => ['nullable', 'string', 'max:255'],
            'ALPACA_API_SECRET' => ['nullable', 'string', 'max:255'],
            'ALPACA_PAPER_TRADING' => ['nullable', 'string', 'max:10'],
            'MAIL_USERNAME' => ['nullable', 'string', 'max:255'],
            'MAIL_PASSWORD' => ['nullable', 'string', 'max:255'],
            'OPENAI_API_KEY' => ['nullable', 'string', 'max:255'],
        ]);

        foreach ($validated as $key => $value) {
            if (in_array($key, ['ALPACA_API_KEY', 'ALPACA_API_SECRET'], strict: true)) {
                continue;
            }
            TradingSettingService::set("trading2.credentials.{$key}", (string) $value);
        }

        $isPaper = ($validated['ALPACA_PAPER_TRADING'] ?? 'true') === 'true';
        $apiKey = $isPaper
            ? ($validated['PAPER_ALPACA_KEY_ID'] ?? '')
            : ($validated['PROD_ALPACA_KEY_ID'] ?? '');
        $apiSecret = $isPaper
            ? ($validated['PAPER_ALPACA_SECRET_KEY'] ?? '')
            : ($validated['PROD_ALPACA_SECRET_KEY'] ?? '');
        TradingSettingService::set('trading2.credentials.ALPACA_API_KEY', $apiKey);
        TradingSettingService::set('trading2.credentials.ALPACA_API_SECRET', $apiSecret);

        $this->writeSecretFile($apiKey, $apiSecret, $isPaper);

        Log::info('[TradingSettings2] Credentials updated by '.auth()->user()?->email);

        return back()->with('status', 'credentials-updated');
    }

    private function updateScorerScripts(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $validated = $request->validate([
            'scorer_scripts' => ['required', 'array'],
            'scorer_scripts.*' => ['required', 'string', 'max:500'],
        ]);

        $envUpdates = [];
        foreach ($validated['scorer_scripts'] as $pipeline => $script) {
            $pipeline = strtolower($pipeline);
            TradingSettingService::set("trading2.scorer_script.{$pipeline}", $script);

            $envKey = 'TRADING_ML_SCORER_SCRIPT_PIPELINE_'.strtoupper($pipeline);
            $envUpdates[$envKey] = $script;
        }

        // Update .env file
        $envPath = base_path('.env');
        if (file_exists($envPath) && is_writable($envPath)) {
            $content = file_get_contents($envPath);
            foreach ($envUpdates as $key => $value) {
                $pattern = "/^{$key}=.*$/m";
                if (preg_match($pattern, $content)) {
                    $content = preg_replace($pattern, "{$key}={$value}", $content);
                } else {
                    $content .= PHP_EOL."{$key}={$value}";
                }
            }
            file_put_contents($envPath, $content);
        }

        Log::info('[TradingSettings2] Scorer scripts updated by '.auth()->user()?->email, [
            'scripts' => $envUpdates,
        ]);

        return back()->with('status', 'scorer-scripts-updated');
    }

    private function updateModelPaths(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'model_paths' => ['required', 'array'],
            'model_paths.*' => ['nullable', 'string', 'max:500'],
        ]);

        \Illuminate\Support\Facades\Log::info('[TradingSettings2] model_paths received', $validated['model_paths']);

        $envUpdates = [];
        foreach ($validated['model_paths'] as $pipeline => $path) {
            // Skip empty paths — don't overwrite .env with blanks
            $path = trim($path ?? '');
            if ($path === '') {
                continue;
            }
            $p = strtolower($pipeline);
            TradingSettingService::set("trading2.model_path.{$p}", $path);
            $envUpdates['TRADING_ML_PIPELINE_'.strtoupper($p).'_MODEL_PATH'] = $path;
        }

        \Illuminate\Support\Facades\Log::info('[TradingSettings2] envUpdates', $envUpdates);

        $envPath = base_path('.env');
        \Illuminate\Support\Facades\Log::error('[TradingSettings2] envPath='.$envPath.' exists='.(file_exists($envPath) ? 'Y' : 'N').' writable='.(is_writable($envPath) ? 'Y' : 'N'));

        if (file_exists($envPath) && is_writable($envPath)) {
            $content = file_get_contents($envPath);
            \Illuminate\Support\Facades\Log::error('[TradingSettings2] BEFORE: '.substr($content, strpos($content, 'TRADING_ML_PIPELINE_C_MODEL_PATH'), 80));
            foreach ($envUpdates as $key => $value) {
                $pattern = "/^{$key}=.*$/m";
                if (preg_match($pattern, $content)) {
                    $content = preg_replace($pattern, "{$key}={$value}", $content);
                } else {
                    $content .= PHP_EOL."{$key}={$value}";
                }
            }
            \Illuminate\Support\Facades\Log::error('[TradingSettings2] AFTER: '.substr($content, strpos($content, 'TRADING_ML_PIPELINE_C_MODEL_PATH'), 80));
            file_put_contents($envPath, $content);
            \Illuminate\Support\Facades\Artisan::call('config:clear');
        } else {
            \Illuminate\Support\Facades\Log::error('[TradingSettings2] Cannot write .env!');
        }

        Log::info('[TradingSettings2] Model paths updated');

        return back()->with('status', 'model-paths-updated');
    }

    private function writeSecretFile(string $apiKey, string $apiSecret, bool $isPaper): void
    {
        $secretPath = base_path('.secret');
        if (! file_exists($secretPath) || ! is_writable($secretPath)) {
            Log::warning('[TradingSettings2] Cannot write .secret');

            return;
        }

        $content = file_get_contents($secretPath);
        $replacements = [
            '/^ALPACA_KEY_ID=.*$/m' => 'ALPACA_KEY_ID='.$apiKey,
            '/^ALPACA_SECRET_KEY=.*$/m' => 'ALPACA_SECRET_KEY='.$apiSecret,
            '/^ALPACA_API_KEY=.*$/m' => 'ALPACA_API_KEY='.$apiKey,
            '/^ALPACA_API_SECRET=.*$/m' => 'ALPACA_API_SECRET='.$apiSecret,
            '/^ALPACA_PAPER_TRADING=.*$/m' => 'ALPACA_PAPER_TRADING='.($isPaper ? 'true' : 'false'),
        ];

        foreach ($replacements as $pattern => $replacement) {
            if (preg_match($pattern, $content)) {
                $content = preg_replace($pattern, $replacement, $content);
            }
        }

        file_put_contents($secretPath, $content);
    }
}
