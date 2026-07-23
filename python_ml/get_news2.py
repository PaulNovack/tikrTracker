#!/usr/bin/env python3

import argparse
import html
import json
import os
import re
import sys
import time
import warnings
from collections import Counter
from datetime import datetime, timezone
from typing import Any

# Configure warning/log suppression before importing libraries that may emit logs.
os.environ["HF_HUB_DISABLE_SYMLINKS_WARNING"] = "1"
os.environ["TRANSFORMERS_NO_ADVISORY_WARNINGS"] = "1"
os.environ["TRANSFORMERS_VERBOSITY"] = "error"
os.environ["TOKENIZERS_PARALLELISM"] = "false"

from pathlib import Path

try:
    import dotenv
    import pymysql
    _HAS_DB = True
except ImportError:
    _HAS_DB = False

import urllib3
from bs4 import (
    BeautifulSoup,
    MarkupResemblesLocatorWarning,
    XMLParsedAsHTMLWarning,
)
from trafilatura import extract, fetch_url
from transformers import logging as hf_logging
from transformers import pipeline


warnings.filterwarnings("ignore", category=UserWarning, module="torch")
warnings.filterwarnings("ignore", category=MarkupResemblesLocatorWarning)
warnings.filterwarnings("ignore", category=XMLParsedAsHTMLWarning)
hf_logging.set_verbosity_error()

MODEL_NAME = "ProsusAI/finbert"
DEFAULT_SYMBOLS = ["AAPL", "TSLA", "NVDA", "GOOG", "MSFT"]

MAX_HEADLINES = 10
MAX_ARTICLES_TO_FETCH = 10
MAX_SENTENCES_PER_ARTICLE = 25

URL_PATTERN = re.compile(r"^https?://", flags=re.IGNORECASE)

HEADERS = {
    "User-Agent": (
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
        "AppleWebKit/537.36 (KHTML, like Gecko) "
        "Chrome/120.0.0.0 Safari/537.36"
    )
}

FINANCIAL_TERMS = (
    "overvalued",
    "undervalued",
    "overbought",
    "oversold",
    "valuation",
    "earnings",
    "revenue",
    "profit",
    "loss",
    "guidance",
    "forecast",
    "upgrade",
    "downgrade",
    "price target",
    "dilution",
    "offering",
    "lawsuit",
    "investigation",
    "approval",
    "demand",
    "growth",
    "breakout",
    "breakdown",
    "bullish",
    "bearish",
)

FINDING_RULES = [
    (
        "Overbought / extended",
        r"\boverbought\b|\bextended\b|\btoo far too fast\b",
    ),
    (
        "Oversold / rebound potential",
        r"\boversold\b|\brelief rally\b|\brebound\b",
    ),
    (
        "Overvalued / expensive",
        (
            r"\bovervalued\b|\bpremium valuation\b|"
            r"\bpriced for perfection\b|\bhigh multiple\b"
        ),
    ),
    (
        "Undervalued / inexpensive",
        r"\bundervalued\b|\battractive valuation\b|\bdiscount to\b",
    ),
    (
        "Analyst downgrade",
        r"\bdowngrad\w*\b|\bprice target (?:cut|lowered|reduced)\b",
    ),
    (
        "Analyst upgrade",
        r"\bupgrad\w*\b|\bprice target (?:raised|increased|lifted)\b",
    ),
    (
        "Earnings beat",
        (
            r"\bbeat\w* estimates\b|\bbetter than expected\b|"
            r"\bearnings beat\b|\brevenue beat\b"
        ),
    ),
    (
        "Earnings miss",
        (
            r"\bmiss\w* estimates\b|\bworse than expected\b|"
            r"\bearnings miss\b|\brevenue miss\b"
        ),
    ),
    (
        "Guidance raised",
        r"\braised guidance\b|\bboosted outlook\b|\blifted forecast\b",
    ),
    (
        "Guidance reduced",
        (
            r"\bcut guidance\b|\blowered forecast\b|"
            r"\breduced outlook\b|\bweak guidance\b"
        ),
    ),
    (
        "Dilution / financing risk",
        (
            r"\bdilution\b|\bshare offering\b|\bstock offering\b|"
            r"\bconvertible notes?\b"
        ),
    ),
    (
        "Regulatory / legal risk",
        (
            r"\binvestigation\b|\blawsuit\b|\bsubpoena\b|"
            r"\bantitrust\b|\bsec probe\b"
        ),
    ),
    (
        "Regulatory approval",
        r"\bapproved\b|\bapproval\b|\bauthorization\b|\bcleared by\b",
    ),
    (
        "Bullish technical momentum",
        r"\bbullish\b|\bbreakout\b|\bnew high\b|\buptrend\b",
    ),
    (
        "Bearish technical pressure",
        r"\bbearish\b|\bbreakdown\b|\bnew low\b|\bdowntrend\b",
    ),
]


def log(message: str) -> None:
    """
    Send progress messages to stderr.

    Keeping diagnostics off stdout guarantees that stdout contains only JSON.
    """
    print(message, file=sys.stderr, flush=True)


def clean_text(value: str | None) -> str:
    """
    Normalize plain text or HTML without asking Beautiful Soup to parse URLs.

    Beautiful Soup emits MarkupResemblesLocatorWarning when a URL is passed as
    markup. URLs are returned unchanged, and Beautiful Soup is only used when
    the value actually appears to contain HTML tags.
    """
    if not value:
        return ""

    value = html.unescape(str(value)).strip()

    if not value:
        return ""

    if URL_PATTERN.match(value):
        return value

    if "<" in value and ">" in value:
        value = BeautifulSoup(value, "html.parser").get_text(" ", strip=True)

    return re.sub(r"\s+", " ", value).strip()


def split_sentences(text: str) -> list[str]:
    text = clean_text(text)

    if not text:
        return []

    sentences = re.split(r"(?<=[.!?])\s+(?=[A-Z0-9\"'])", text)

    return [
        sentence.strip()
        for sentence in sentences
        if 35 <= len(sentence.strip()) <= 500
    ]


def select_article_sentences(text: str) -> list[str]:
    sentences = split_sentences(text)

    if len(sentences) <= MAX_SENTENCES_PER_ARTICLE:
        return sentences

    first_sentences = sentences[:8]
    ranked = sorted(
        sentences,
        key=lambda sentence: sum(
            term in sentence.lower()
            for term in FINANCIAL_TERMS
        ),
        reverse=True,
    )

    selected: list[str] = []
    seen: set[str] = set()

    for sentence in first_sentences + ranked:
        key = sentence.lower()

        if key in seen:
            continue

        selected.append(sentence)
        seen.add(key)

        if len(selected) >= MAX_SENTENCES_PER_ARTICLE:
            break

    return selected


def load_finbert() -> Any:
    log(f"Initializing FinBERT model: {MODEL_NAME}")

    return pipeline(
        "text-classification",
        model=MODEL_NAME,
        top_k=None,
    )


def classify_texts(
    finbert: Any,
    texts: list[str],
) -> list[dict[str, Any]]:
    texts = [clean_text(text) for text in texts]
    texts = [text for text in texts if text]

    if not texts:
        return []

    raw_predictions = finbert(
        texts,
        truncation=True,
        max_length=512,
        batch_size=16,
    )

    # Some Transformers versions flatten one-input results.
    if raw_predictions and isinstance(raw_predictions[0], dict):
        raw_predictions = [raw_predictions]

    results: list[dict[str, Any]] = []

    for text, predictions in zip(texts, raw_predictions):
        probabilities = {
            prediction["label"].lower(): float(prediction["score"])
            for prediction in predictions
        }

        positive = probabilities.get("positive", 0.0)
        negative = probabilities.get("negative", 0.0)
        neutral = probabilities.get("neutral", 0.0)
        impact = positive - negative

        results.append({
            "text": text,
            "positive": positive,
            "negative": negative,
            "neutral": neutral,
            "impact": impact,
            "label": max(probabilities, key=probabilities.get),
        })

    return results


def finding_category(
    text: str,
    impact: float,
) -> tuple[str, str | None]:
    for category, pattern in FINDING_RULES:
        match = re.search(pattern, text, flags=re.IGNORECASE)

        if match:
            return category, match.group(0)

    if impact > 0.15:
        return "General positive financial development", None

    if impact < -0.15:
        return "General negative financial development", None

    return "Mixed or neutral information", None


def sentiment_label(
    impact: float,
    neutral: float = 0.0,
) -> str:
    if neutral >= 0.60 or abs(impact) < 0.10:
        return "neutral"

    return "positive" if impact > 0 else "negative"


def fetch_main_article_text(url: str) -> str:
    """
    Best-effort article extraction.

    Some publishers block scraping, require JavaScript, use paywalls, or do not
    redirect cleanly from Google News. In those cases, an empty string is
    returned and analysis falls back to the RSS title and description.
    """
    try:
        downloaded = fetch_url(url)

        if not downloaded:
            return ""

        article_text = extract(
            downloaded,
            include_comments=False,
            include_tables=False,
            favor_precision=True,
            deduplicate=True,
        )

        return clean_text(article_text)
    except Exception:
        return ""


# ═══════════════════════════════════════════════════════════════
# Database-backed company-name loader for relevance filtering
# ═══════════════════════════════════════════════════════════════

_env_path = Path(__file__).resolve().parents[1] / ".env"
dotenv.load_dotenv(_env_path)


def _get_db_connection():
    if not _HAS_DB:
        raise RuntimeError("pymysql not available — install with: pip install pymysql")

    return pymysql.connect(
        host=os.environ.get("DB_HOST", "127.0.0.1"),
        port=int(os.environ.get("DB_PORT", "3306")),
        user=os.environ.get("DB_USERNAME", "laravel"),
        password=os.environ.get("DB_PASSWORD", ""),
        database=os.environ.get("DB_DATABASE", "laravelInvest"),
        charset="utf8mb4",
        cursorclass=pymysql.cursors.DictCursor,
    )


def load_company_aliases() -> dict[str, str]:
    """
    Build {symbol: company_name} from the ``asset_info`` table.
    ...
    """
    if not _HAS_DB:
        return {}

    GENERIC_WORDS = {
        "common", "stock", "corporation", "corp", "inc",
        "ltd", "limited", "class", "share", "shares",
        "company", "co", "plc", "holding", "holdings",
        "group", "international", "the",
    }

    aliases: dict[str, str] = {}

    try:
        conn = _get_db_connection()

        with conn.cursor() as cur:
            cur.execute("SELECT symbol, common_name FROM asset_info")

            for row in cur.fetchall():
                symbol = str(row["symbol"]).strip().upper()
                name = (row["common_name"] or "").strip()

                if symbol and name:
                    # Remove generic words.
                    meaningful = [
                        w for w in name.lower().split()
                        if w not in GENERIC_WORDS
                    ]

                    if meaningful:
                        aliases[symbol] = " ".join(meaningful)

        conn.close()
    except Exception as exc:
        log(f"WARNING: could not load company names from DB ({exc})")

    return aliases


# Loaded once at module level (lazy, but cached per process).
_company_aliases: dict[str, str] | None = None


def get_company_aliases() -> dict[str, str]:
    global _company_aliases

    if _company_aliases is None:
        _company_aliases = load_company_aliases()

    return _company_aliases


def is_article_relevant(title: str, symbol: str) -> bool:
    """
    Check if an article title is actually about the given symbol.

    Google News RSS returns loosely-related results for short symbols
    (e.g. "AA" matching "American Airlines" or "Alcon"). We reject
    articles that don't mention the symbol as a whole word (or the
    company's common name from the ``asset_info`` table) in the title.
    """
    title_lower = title.lower()
    symbol_lower = symbol.lower()

    # Whole-word match on the symbol itself.
    if re.search(rf"\b{re.escape(symbol_lower)}\b", title_lower):
        return True

    # Try to match the company's common name from the database.
    aliases = get_company_aliases()
    company = aliases.get(symbol.upper())

    if company:
        for part in company.split(" "):
            if len(part) < 3:
                continue

            if re.search(rf"\b{re.escape(part)}\b", title_lower):
                return True

    log(f"  REJECT (no match): symbol={symbol} company={company} titleshort={title[:70]}")
    return False


def parse_rss_items(xml_data: bytes, symbol: str) -> list[dict[str, str]]:
    soup = BeautifulSoup(xml_data, "xml")
    items: list[dict[str, str]] = []
    seen_titles: set[str] = set()

    for item in soup.find_all("item"):
        title = clean_text(
            item.title.get_text()
            if item.title
            else ""
        )
        link = clean_text(
            item.link.get_text()
            if item.link
            else ""
        )
        description = clean_text(
            item.description.decode_contents()
            if item.description
            else ""
        )
        source = clean_text(
            item.source.get_text()
            if item.source
            else ""
        )
        pub_date = clean_text(
            item.pubDate.get_text()
            if item.pubDate
            else ""
        )

        if source and title.endswith(f" - {source}"):
            title = title[:-(len(source) + 3)].strip()

        if not title or title.lower() in seen_titles:
            continue

        if not is_article_relevant(title, symbol):
            log(f"  SKIP (irrelevant): {title[:80]}")
            continue

        seen_titles.add(title.lower())
        items.append({
            "title": title,
            "link": link,
            "description": description,
            "source": source,
            "pub_date": pub_date,
        })

        if len(items) >= MAX_HEADLINES:
            break

    return items


def analyze_article(
    finbert: Any,
    item: dict[str, str],
    fetch_full_text: bool,
) -> dict[str, Any] | None:
    article_text = (
        fetch_main_article_text(item["link"])
        if fetch_full_text and item["link"]
        else ""
    )

    candidate_texts = [item["title"]]

    if (
        item["description"]
        and item["description"] != item["title"]
    ):
        candidate_texts.append(item["description"])

    if article_text:
        candidate_texts.extend(
            select_article_sentences(article_text)
        )

    scored = classify_texts(finbert, candidate_texts)

    if not scored:
        return None

    headline = scored[0]
    evidence_rows = scored[1:]

    strongest_evidence = (
        max(
            evidence_rows,
            key=lambda row: abs(row["impact"]),
        )
        if evidence_rows
        else headline
    )

    # The headline remains the primary signal. The strongest supporting
    # passage receives a smaller weight when article text is available.
    if evidence_rows:
        article_impact = (
            headline["impact"] * 0.60
            + strongest_evidence["impact"] * 0.40
        )
        article_neutral = (
            headline["neutral"] * 0.60
            + strongest_evidence["neutral"] * 0.40
        )
    else:
        article_impact = headline["impact"]
        article_neutral = headline["neutral"]

    strongest_driver = max(
        [headline, *evidence_rows],
        key=lambda row: abs(row["impact"]),
    )

    category, matched_phrase = finding_category(
        strongest_driver["text"],
        strongest_driver["impact"],
    )

    return {
        "title": item["title"],
        "source": item["source"] or None,
        "url": item["link"] or None,
        "pub_date": item.get("pub_date") or None,
        "article_text_extracted": bool(article_text),
        "sentiment": sentiment_label(
            article_impact,
            article_neutral,
        ),
        "impact": round(article_impact, 4),
        "score_1_100": max(
            1,
            min(100, round(50 + article_impact * 50)),
        ),
        "finding_category": category,
        "matched_phrase": matched_phrase,
        "evidence": strongest_driver["text"],
        "evidence_probabilities": {
            "positive": round(
                strongest_driver["positive"],
                4,
            ),
            "negative": round(
                strongest_driver["negative"],
                4,
            ),
            "neutral": round(
                strongest_driver["neutral"],
                4,
            ),
        },
    }


def empty_symbol_result(
    error: str | None = None,
) -> dict[str, Any]:
    result: dict[str, Any] = {
        "sentiment": "No usable headlines found",
        "confidence": 0.0,
        "sentiment_score_1_100": 50,
        "headline_count": 0,
        "breakdown": {
            "positive": 0,
            "negative": 0,
            "neutral": 0,
        },
        "top_explanation": None,
        "key_findings": [],
    }

    if error:
        result["error"] = error

    return result


def summarize_symbol(
    article_results: list[dict[str, Any]],
) -> dict[str, Any]:
    if not article_results:
        return empty_symbol_result()

    weights = [
        max(0.10, abs(result["impact"]))
        for result in article_results
    ]

    combined_impact = sum(
        result["impact"] * weight
        for result, weight in zip(article_results, weights)
    ) / sum(weights)

    distribution = Counter(
        result["sentiment"]
        for result in article_results
    )

    top_driver = max(
        article_results,
        key=lambda result: abs(result["impact"]),
    )

    return {
        "sentiment": sentiment_label(
            combined_impact,
        ).capitalize(),
        "confidence": round(
            abs(combined_impact) * 100,
            1,
        ),
        "sentiment_score_1_100": max(
            1,
            min(100, round(50 + combined_impact * 50)),
        ),
        "headline_count": len(article_results),
        "breakdown": {
            "positive": distribution.get("positive", 0),
            "negative": distribution.get("negative", 0),
            "neutral": distribution.get("neutral", 0),
        },
        "top_explanation": {
            "finding": top_driver["finding_category"],
            "matched_phrase": top_driver["matched_phrase"],
            "evidence": top_driver["evidence"],
            "source": top_driver["source"],
            "title": top_driver["title"],
            "article_score_1_100": top_driver["score_1_100"],
            "article_text_extracted": (
                top_driver["article_text_extracted"]
            ),
            "url": top_driver["url"],
        },
        "key_findings": sorted(
            article_results,
            key=lambda result: abs(result["impact"]),
            reverse=True,
        )[:10],
    }


def get_finbert_sentiment_rss_direct(
    finbert: Any,
    symbols: list[str],
    fetch_article_text: bool = True,
) -> dict[str, dict[str, Any]]:
    http = urllib3.PoolManager(headers=HEADERS)
    results: dict[str, dict[str, Any]] = {}

    for raw_symbol in symbols:
        symbol = raw_symbol.strip().upper()

        if not symbol:
            continue

        rss_url = (
            "https://news.google.com/rss/search"
            f"?q={symbol}+stock+when:1d"
            "&hl=en-US&gl=US&ceid=US:en"
        )

        try:
            log(f"Fetching news for {symbol}...")

            response = http.request(
                "GET",
                rss_url,
                timeout=urllib3.Timeout(
                    connect=5.0,
                    read=12.0,
                ),
                retries=False,
            )

            if response.status != 200:
                results[symbol] = empty_symbol_result(
                    error=f"Google News RSS returned HTTP {response.status}",
                )
                continue

            rss_items = parse_rss_items(response.data, symbol)
            article_results: list[dict[str, Any]] = []

            for index, item in enumerate(rss_items):
                analyzed = analyze_article(
                    finbert,
                    item,
                    fetch_full_text=(
                        fetch_article_text
                        and index < MAX_ARTICLES_TO_FETCH
                    ),
                )

                if analyzed:
                    article_results.append(analyzed)

            results[symbol] = summarize_symbol(article_results)
            time.sleep(1)

        except Exception as exc:
            log(f"Error processing {symbol}: {exc}")
            results[symbol] = empty_symbol_result(
                error=str(exc),
            )

    return results


def parse_arguments() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description=(
            "Fetch Google News RSS headlines, analyze them with FinBERT, "
            "and write valid JSON to stdout."
        ),
    )
    parser.add_argument(
        "symbols",
        nargs="*",
        help=(
            "Stock symbols to analyze. Defaults to: "
            + ", ".join(DEFAULT_SYMBOLS)
        ),
    )
    parser.add_argument(
        "--no-article-text",
        action="store_true",
        help=(
            "Analyze only RSS titles/descriptions and skip full-article "
            "extraction."
        ),
    )
    parser.add_argument(
        "--compact",
        action="store_true",
        help="Write compact JSON instead of indented JSON.",
    )

    return parser.parse_args()


def main() -> int:
    args = parse_arguments()
    symbols = args.symbols or DEFAULT_SYMBOLS

    try:
        finbert = load_finbert()

        sentiment_data = get_finbert_sentiment_rss_direct(
            finbert,
            symbols,
            fetch_article_text=not args.no_article_text,
        )

        payload = {
            "success": True,
            "generated_at_utc": datetime.now(
                timezone.utc,
            ).isoformat(),
            "model": MODEL_NAME,
            "query_window": "1d",
            "symbols_requested": [
                symbol.strip().upper()
                for symbol in symbols
                if symbol.strip()
            ],
            "results": sentiment_data,
        }

        json.dump(
            payload,
            sys.stdout,
            ensure_ascii=False,
            indent=None if args.compact else 2,
            allow_nan=False,
        )
        sys.stdout.write("\n")
        return 0

    except Exception as exc:
        # Even fatal errors are returned as valid JSON on stdout.
        payload = {
            "success": False,
            "generated_at_utc": datetime.now(
                timezone.utc,
            ).isoformat(),
            "model": MODEL_NAME,
            "error": str(exc),
            "results": {},
        }

        json.dump(
            payload,
            sys.stdout,
            ensure_ascii=False,
            indent=None if args.compact else 2,
            allow_nan=False,
        )
        sys.stdout.write("\n")
        return 1


if __name__ == "__main__":
    raise SystemExit(main())