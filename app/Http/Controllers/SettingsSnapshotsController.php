<?php

namespace App\Http\Controllers;

use App\Models\SettingsSnapshot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsSnapshotsController extends Controller
{
    /**
     * List all snapshots and current settings.
     */
    public function index(): Response
    {
        $snapshots = SettingsSnapshot::orderBy('updated_at', 'desc')
            ->get(['id', 'name', 'data', 'created_at', 'updated_at'])
            ->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'key_count' => is_countable($s->data) ? count($s->data) : 0,
                'created_at' => $s->created_at->toISOString(),
                'updated_at' => $s->updated_at->toISOString(),
            ]);

        return Inertia::render('system/SettingsSnapshots', [
            'snapshots' => $snapshots,
        ]);
    }

    /**
     * Create a snapshot from current settings.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:settings_snapshots,name'],
        ]);

        SettingsSnapshot::createFromCurrent($request->input('name'));

        return back()->with('success', "Snapshot \"{$request->input('name')}\" created.");
    }

    /**
     * Restore a snapshot.
     */
    public function restore(Request $request, SettingsSnapshot $snapshot): RedirectResponse
    {
        $snapshot->restoreAll();

        return back()->with('success', "Snapshot \"{$snapshot->name}\" restored (".count((array) $snapshot->data).' settings).');
    }

    /**
     * Delete a snapshot.
     */
    public function destroy(SettingsSnapshot $snapshot): RedirectResponse
    {
        $name = $snapshot->name;
        $snapshot->delete();

        return back()->with('success', "Snapshot \"{$name}\" deleted.");
    }

    /**
     * AJAX: Return all key/value pairs for a snapshot.
     */
    public function show(SettingsSnapshot $snapshot): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'id' => $snapshot->id,
            'name' => $snapshot->name,
            'keys' => $snapshot->data,
            'created_at' => $snapshot->created_at->toISOString(),
        ]);
    }
}
