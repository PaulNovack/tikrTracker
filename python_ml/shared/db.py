"""
shared/db.py — Database connection helpers.

Single source of truth for DBConfig, engine creation, and env loading.
Used by all v4 trainers and scorers.
"""

from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path
from typing import Optional

from dotenv import load_dotenv
from sqlalchemy import create_engine
from sqlalchemy.engine import URL


@dataclass
class DBConfig:
    host: str
    port: int
    name: str
    user: str
    password: str


def load_parent_env() -> None:
    """Load .env from the project root (parent of python_ml/)."""
    env_path = Path(__file__).resolve().parents[2] / ".env"
    if env_path.exists():
        load_dotenv(dotenv_path=env_path, override=False)


def get_db_config_from_env() -> DBConfig:
    load_parent_env()
    return DBConfig(
        host=os.environ.get("DB_HOST", "127.0.0.1"),
        port=int(os.environ.get("DB_PORT", "3306")),
        name=os.environ.get("DB_DATABASE", os.environ.get("DB_NAME", "trading")),
        user=os.environ.get("DB_USERNAME", os.environ.get("DB_USER", "root")),
        password=os.environ.get("DB_PASSWORD", os.environ.get("DB_PASS", "")),
    )


def make_engine(cfg: Optional[DBConfig] = None, **engine_kwargs):
    """Build SQLAlchemy engine using URL.create() — safe with special characters."""
    if cfg is None:
        cfg = get_db_config_from_env()
    url = URL.create(
        "mysql+pymysql",
        username=cfg.user,
        password=cfg.password,
        host=cfg.host,
        port=cfg.port,
        database=cfg.name,
    )
    defaults = {"pool_pre_ping": True}
    defaults.update(engine_kwargs)
    return create_engine(url, **defaults)


def get_benchmark_symbol() -> str:
    load_parent_env()
    return os.environ.get("TRADING_MARKET_BENCHMARK_SYMBOL", "QQQ")
