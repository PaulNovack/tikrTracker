"""Reads Laravel .env file from parent directory for database connection."""
import os
import re

from sqlalchemy import create_engine


def load_env(env_path: str | None = None) -> dict[str, str]:
    """Parse a .env file into a dict, ignoring comments and empty lines."""
    if env_path is None:
        env_path = os.path.join(os.path.dirname(__file__), '..', '.env')

    values: dict[str, str] = {}

    if not os.path.exists(env_path):
        raise FileNotFoundError(f'.env file not found at {env_path}')

    with open(env_path) as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith('#'):
                continue
            if '=' not in line:
                continue
            key, _, value = line.partition('=')
            key = key.strip()
            value = value.strip().strip('"').strip("'")
            values[key] = value

    return values


def get_engine(env_path: str | None = None):
    """Return a SQLAlchemy engine configured from the Laravel .env."""
    env = load_env(env_path)

    user = env.get('DB_USERNAME', 'laravel')
    password = env.get('DB_PASSWORD', '')
    host = env.get('DB_HOST', '127.0.0.1')
    port = env.get('DB_PORT', '3306')
    database = env.get('DB_DATABASE', 'laravelInvest')
    charset = env.get('DB_CHARSET', 'utf8mb4')

    url = f'mysql+pymysql://{user}:{password}@{host}:{port}/{database}?charset={charset}'
    return create_engine(url)
