#!/usr/bin/env python3
"""
v2/scripts/rescore_missed.py — Rescore only unscored alerts for specific pipelines.

Unlike rescore_all_pipelines.py, this does NOT clear existing scores.
It only fills in ml_scored_at=NULL rows. Safe to run anytime.

Usage:
  python python_ml/v2/scripts/rescore_missed.py --pipelines A,C,G,M,O
  python python_ml/v2/scripts/rescore_missed.py --pipelines A,C,G,M,O --workers 8
"""
import os
import sys
import subprocess
import argparse
from concurrent.futures import ThreadPoolExecutor, as_completed
from pathlib import Path
from dotenv import load_dotenv

SCRIPT_DIR = Path(__file__).resolve().parent
V2_DIR = SCRIPT_DIR.parent
PYTHON_ML_DIR = V2_DIR.parent
REPO_ROOT = PYTHON_ML_DIR.parent

load_dotenv(dotenv_path=REPO_ROOT / ".env", override=False)
SCORE_SCRIPT = str(V2_DIR / "score_trade_alerts.py")


def get_db_engine():
    from sqlalchemy import create_engine
    host = os.environ.get("DB_HOST", "127.0.0.1")
    port = int(os.environ.get("DB_PORT", "3306"))
    name = os.environ.get("DB_DATABASE", "laravelInvest")
    user = os.environ.get("DB_USERNAME", "laravel")
    password = os.environ.get("DB_PASSWORD", "laravel")
    url = f"mysql+pymysql://{user}:{password}@{host}:{port}/{name}"
    return create_engine(url, pool_pre_ping=True)


def get_unscored_dates(engine, pipelines: list[str]) -> list[str]:
    """Get distinct trading dates with unscored alerts for the given pipelines."""
    from sqlalchemy import text
    placeholders = ",".join([f"'{p}'" for p in pipelines])
    with engine.connect() as conn:
        result = conn.execute(
            text(
                f"SELECT DISTINCT trading_date_est FROM trade_alerts "
                f"WHERE pipeline_run IN ({placeholders}) "
                f"AND ml_scored_at IS NULL "
                f"ORDER BY trading_date_est"
            )
        )
        return [
            row[0].strftime("%Y-%m-%d") if hasattr(row[0], 'strftime') else str(row[0])
            for row in result
        ]


def count_unscored(engine, pipeline: str) -> int:
    from sqlalchemy import text
    with engine.connect() as conn:
        result = conn.execute(
            text(
                f"SELECT COUNT(*) FROM trade_alerts "
                f"WHERE pipeline_run = '{pipeline}' AND ml_scored_at IS NULL"
            )
        )
        return result.scalar() or 0


def process_pipeline(pipeline: str, args):
    """Score all unscored alerts for a single pipeline."""
    from sqlalchemy import create_engine

    host = os.environ.get("DB_HOST", "127.0.0.1")
    port = int(os.environ.get("DB_PORT", "3306"))
    name = os.environ.get("DB_DATABASE", "laravelInvest")
    user = os.environ.get("DB_USERNAME", "laravel")
    password = os.environ.get("DB_PASSWORD", "laravel")
    engine_url = f"mysql+pymysql://{user}:{password}@{host}:{port}/{name}"

    engine = create_engine(engine_url, pool_pre_ping=True)

    unscored = count_unscored(engine, pipeline)
    print(f"[{pipeline}] {unscored} unscored alerts")

    if unscored == 0:
        print(f"[{pipeline}] Nothing to do.")
        engine.dispose()
        return

    dates = get_unscored_dates(engine, [pipeline])
    print(f"[{pipeline}] {len(dates)} dates to process")

    for i, date in enumerate(dates, 1):
        # Resolve model path per pipeline
        key = f"TRADING_ML_PIPELINE_{pipeline.upper()}_MODEL_PATH"
        model_path = os.environ.get(key, "").strip()
        if not model_path or not Path(model_path).exists():
            print(f"[{pipeline}] [{i}/{len(dates)}] {date} ⚠️  No model found, skipping.")
            continue

        result = subprocess.run(
            [
                sys.executable, SCORE_SCRIPT,
                "--model-in", model_path,
                "--trading-date", date,
                "--pipeline", pipeline,
                "--limit", str(args.limit),
            ],
            capture_output=True, text=True,
            cwd=str(REPO_ROOT),
        )
        if result.returncode != 0:
            print(f"[{pipeline}] [{i}/{len(dates)}] {date} ⚠️  FAILED: {result.stderr.strip()[-200:]}")
        else:
            output = result.stdout
            if "No unscored alerts" in output:
                print(f"[{pipeline}] [{i}/{len(dates)}] {date} ✅ No unscored alerts.")
            else:
                print(f"[{pipeline}] [{i}/{len(dates)}] {date} ✅ Done.")

    remaining = count_unscored(engine, pipeline)
    print(f"[{pipeline}] Complete. {remaining} remaining unscored.")
    engine.dispose()


def main():
    parser = argparse.ArgumentParser(description="Rescore only unscored alerts")
    parser.add_argument("--pipelines", required=True, help="Comma-separated pipeline letters (e.g. A,C,G)")
    parser.add_argument("--limit", type=int, default=200000, help="Max alerts per date")
    parser.add_argument("--workers", type=int, default=4, help="Parallel pipelines")
    args = parser.parse_args()

    pipeline_list = [p.strip().upper() for p in args.pipelines.split(",") if p.strip()]

    print("=" * 60)
    print("  Rescore Missed (unscored only)")
    print(f"  Pipelines: {','.join(pipeline_list)}")
    print(f"  Workers: {args.workers}")
    print("=" * 60)
    print()

    max_workers = min(args.workers, len(pipeline_list))
    with ThreadPoolExecutor(max_workers=max_workers) as executor:
        futures = {
            executor.submit(process_pipeline, p, args): p
            for p in pipeline_list
        }
        for future in as_completed(futures):
            p = futures[future]
            try:
                future.result()
            except Exception as exc:
                print(f"[{p}] ERROR: {exc}")

    print()
    print("=" * 60)
    print("Rescore Missed Complete!")
    print("=" * 60)


if __name__ == "__main__":
    main()
