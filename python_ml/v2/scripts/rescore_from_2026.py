#!/usr/bin/env python3
"""
v2/scripts/rescore_from_2026.py — Rescore all pipelines from 2026-01-01 onward.

Clears existing ML scores for dates >= 2026-01-01 (sets ml_scored_at=NULL, ml_win_prob=NULL),
then re-scores each trading date using score_trade_alerts.py with the current per-pipeline model.

Usage:
  python python_ml/v2/scripts/rescore_from_2026.py
  python python_ml/v2/scripts/rescore_from_2026.py --start-date 2026-06-01
  python python_ml/v2/scripts/rescore_from_2026.py --pipelines K,L,J
  python python_ml/v2/scripts/rescore_from_2026.py --limit 50000
  python python_ml/v2/scripts/rescore_from_2026.py --dry-run
"""
import os
import sys
import argparse
import subprocess
from concurrent.futures import ThreadPoolExecutor, as_completed
from pathlib import Path
from dotenv import load_dotenv

SCRIPT_DIR = Path(__file__).resolve().parent
V2_DIR = SCRIPT_DIR.parent
PYTHON_ML_DIR = V2_DIR.parent
REPO_ROOT = PYTHON_ML_DIR.parent

load_dotenv(dotenv_path=REPO_ROOT / ".env", override=False)

SCORE_SCRIPT = str(V2_DIR / "score_trade_alerts.py")
DEFAULT_PIPELINES = "A,B,C,D,E,F,G,H,I,J,K,L,M,N,O,P,Q,R"


def get_model_path(pipeline: str) -> str:
    key = f"TRADING_ML_PIPELINE_{pipeline.upper()}_MODEL_PATH"
    return os.environ.get(key, "").strip()


def get_db_engine():
    from sqlalchemy import create_engine
    host = os.environ.get("DB_HOST", "127.0.0.1")
    port = int(os.environ.get("DB_PORT", "3306"))
    name = os.environ.get("DB_DATABASE", "laravelInvest")
    user = os.environ.get("DB_USERNAME", "laravel")
    password = os.environ.get("DB_PASSWORD", "laravel")
    url = f"mysql+pymysql://{user}:{password}@{host}:{port}/{name}"
    return create_engine(url, pool_pre_ping=True)


def clear_scores(engine, pipelines: list[str], start_date: str):
    """Set ml_scored_at and ml_win_prob to NULL for matching alerts."""
    from sqlalchemy import text
    placeholders = ",".join([f"'{p}'" for p in pipelines])
    with engine.begin() as conn:
        result = conn.execute(
            text(
                f"SELECT COUNT(*) FROM trade_alerts "
                f"WHERE pipeline_run IN ({placeholders}) "
                f"AND trading_date_est >= :start"
            ),
            {"start": start_date},
        )
        total = result.scalar()
        if total == 0:
            print(f"  No alerts found for dates >= {start_date}.")
            return

        conn.execute(
            text(
                f"UPDATE trade_alerts SET ml_scored_at = NULL, ml_win_prob = NULL, passed_ml = 0 "
                f"WHERE pipeline_run IN ({placeholders}) "
                f"AND trading_date_est >= :start"
            ),
            {"start": start_date},
        )
        print(f"  Cleared scores for {total} alerts since {start_date}.")


def get_dates(engine, pipelines: list[str], start_date: str) -> list[str]:
    """Get distinct unscored trading dates >= start_date for the given pipelines."""
    from sqlalchemy import text
    placeholders = ",".join([f"'{p}'" for p in pipelines])
    with engine.connect() as conn:
        result = conn.execute(
            text(
                f"SELECT DISTINCT trading_date_est FROM trade_alerts "
                f"WHERE pipeline_run IN ({placeholders}) "
                f"AND ml_scored_at IS NULL "
                f"AND trading_date_est >= :start "
                f"ORDER BY trading_date_est"
            ),
            {"start": start_date},
        )
        return [
            row[0].strftime("%Y-%m-%d") if hasattr(row[0], "strftime") else str(row[0])
            for row in result
        ]


def process_model_group(model_path: str, pipes: list[str], args, engine_url: str):
    """Clear scores then rescore all dates from start_date forward."""
    from sqlalchemy import create_engine
    pipe_csv = ",".join(pipes)
    print(f"[{pipe_csv}] Starting...")

    engine = create_engine(engine_url, pool_pre_ping=True)

    if args.dry_run:
        print(f"[{pipe_csv}] DRY RUN: would clear scores and rescore from {args.start_date}")
        engine.dispose()
        return

    # Step 1: Clear existing scores
    clear_scores(engine, pipes, args.start_date)

    # Step 2: Get dates
    dates = get_dates(engine, pipes, args.start_date)
    print(f"[{pipe_csv}] Found {len(dates)} trading dates to rescore since {args.start_date}.")
    if not dates:
        print(f"[{pipe_csv}] No dates to rescore, done.")
        engine.dispose()
        return

    # Step 3: Score each date
    for i, date in enumerate(dates, 1):
        result = subprocess.run(
            [
                sys.executable, SCORE_SCRIPT,
                "--model-in", model_path,
                "--trading-date", date,
                "--pipeline", pipe_csv,
                "--limit", str(args.limit),
            ],
            capture_output=True, text=True,
            cwd=str(REPO_ROOT),
        )
        if result.returncode != 0:
            print(f"[{pipe_csv}] [{i}/{len(dates)}] {date} ⚠️  FAILED: {result.stderr.strip()[-200:]}")
        else:
            output = result.stdout
            if "No unscored alerts" in output:
                print(f"[{pipe_csv}] [{i}/{len(dates)}] {date} ✅ No alerts to score.")
            else:
                print(f"[{pipe_csv}] [{i}/{len(dates)}] {date} ✅ Done.")

    engine.dispose()
    print(f"[{pipe_csv}] Complete.")


def main():
    parser = argparse.ArgumentParser(description="Rescore pipelines from 2026-01-01 onward")
    parser.add_argument("--pipelines", default=DEFAULT_PIPELINES, help="Comma-separated pipeline letters")
    parser.add_argument("--start-date", default="2026-01-01", help="Start date YYYY-MM-DD (default: 2026-01-01)")
    parser.add_argument("--limit", type=int, default=200000, help="Max alerts per date")
    parser.add_argument("--dry-run", action="store_true", help="Print what would happen")
    parser.add_argument("--workers", type=int, default=4, help="Number of parallel model groups")
    args = parser.parse_args()

    pipeline_list = [p.strip().upper() for p in args.pipelines.split(",") if p.strip()]

    print("=" * 60)
    print("  Rescore from 2026 Forward")
    print(f"  Pipelines: {','.join(pipeline_list)}")
    print(f"  Start date: {args.start_date}")
    print(f"  Limit per date: {args.limit}")
    print(f"  Parallel workers: {args.workers}")
    if args.dry_run:
        print("  Mode: DRY RUN")
    print("=" * 60)
    print()

    # Build model -> pipelines mapping
    model_to_pipelines = {}
    for pipeline in pipeline_list:
        model_path = get_model_path(pipeline)
        if not model_path:
            print(f"WARN: No model path for pipeline {pipeline}, skipping.")
            continue
        if not Path(model_path).exists():
            print(f"WARN: Model not found: {model_path}, skipping pipeline {pipeline}.")
            continue
        model_to_pipelines.setdefault(model_path, []).append(pipeline)

    if not model_to_pipelines:
        print("ERROR: No valid pipelines found.")
        sys.exit(1)

    print("Resolved model groups:")
    for mp, pipes in model_to_pipelines.items():
        print(f"  {mp} -> {','.join(pipes)}")
    print()

    host = os.environ.get("DB_HOST", "127.0.0.1")
    port = int(os.environ.get("DB_PORT", "3306"))
    name = os.environ.get("DB_DATABASE", "laravelInvest")
    user = os.environ.get("DB_USERNAME", "laravel")
    password = os.environ.get("DB_PASSWORD", "laravel")
    engine_url = f"mysql+pymysql://{user}:{password}@{host}:{port}/{name}"

    max_workers = min(args.workers, len(model_to_pipelines))
    with ThreadPoolExecutor(max_workers=max_workers) as executor:
        futures = {
            executor.submit(process_model_group, mp, pipes, args, engine_url): pipes
            for mp, pipes in model_to_pipelines.items()
        }
        for future in as_completed(futures):
            pipes = futures[future]
            try:
                future.result()
            except Exception as exc:
                print(f"[{','.join(pipes)}] ERROR: {exc}")

    print()
    print("=" * 60)
    print("Rescore Complete!")
    print("=" * 60)


if __name__ == "__main__":
    main()
