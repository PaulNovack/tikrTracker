"""Redis-based caching for the candlestick screener Flask app.

Uses the REDIS_HOST/REDIS_PORT from the Laravel .env file.
Default TTL: 8 hours (28800 seconds).
"""

import pickle
from functools import wraps

import redis

from db_config import load_env


def _get_redis_client() -> redis.Redis:
    env = load_env()
    return redis.Redis(
        host=env.get('REDIS_HOST', '127.0.0.1'),
        port=int(env.get('REDIS_PORT', 6379)),
        password=env.get('REDIS_PASSWORD') or None,
        decode_responses=False,
    )


def cached(ttl_seconds: int = 28800):
    """Decorator that caches function return values in Redis with an 8-hr TTL.

    The cache key is built from the function name and stringified arguments.
    Values are serialized with pickle.
    """

    def decorator(func):
        @wraps(func)
        def wrapper(*args, **kwargs):
            # Build a unique cache key from function name + args + kwargs
            key_parts = [func.__name__]
            key_parts.extend(str(a) for a in args)
            key_parts.extend(f'{k}={v}' for k, v in sorted(kwargs.items()))
            cache_key = f'flask_cache:{"|".join(key_parts)}'

            r = _get_redis_client()
            cached_value = r.get(cache_key)

            if cached_value is not None:
                return pickle.loads(cached_value)

            result = func(*args, **kwargs)
            r.setex(cache_key, ttl_seconds, pickle.dumps(result))

            return result

        return wrapper

    return decorator


def invalidate_cache():
    """Delete all flask_cache:* keys. Call on new trading day or data refresh."""
    r = _get_redis_client()
    keys = r.keys('flask_cache:*')
    if keys:
        r.delete(*keys)
