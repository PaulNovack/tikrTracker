import os
import time
import warnings

# 1. Silencing standard Python warnings and framework specific warnings
warnings.filterwarnings("ignore", category=UserWarning, module="torch")
try:
    from bs4 import XMLParsedAsHTMLWarning
    warnings.filterwarnings("ignore", category=XMLParsedAsHTMLWarning)
except ImportError:
    pass

# Suppress additional internal Hugging Face framework telemetry logs
os.environ["HF_HUB_DISABLE_SYMLINKS_WARNING"] = "1"

from bs4 import BeautifulSoup
from transformers import pipeline
import urllib3

print("Initializing FinBERT model...")

# 2. Added 'disable_tqdm=True' to completely turn off the loading progress bar
finbert = pipeline(
    "text-classification", 
    model="ProsusAI/finbert", 
    top_k=None, 
    disable_tqdm=True
)


def get_finbert_sentiment_rss_direct(symbols):
    http_engine = urllib3.PoolManager()
    
    browser_headers = {
        "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"
    }
    results = {}

    for symbol in symbols:
        destination_pieces = [
            "https://",
            "news.",
            "google.com",
            "/rss",
            "/search",
            "?q="
        ]
        
        target_endpoint = "".join(destination_pieces) + str(symbol) + "+stock+when:1d&hl=en-US&gl=US&ceid=US:en"

        try:
            print(f"Fetching data for {symbol}...")
            server_response = http_engine.request("GET", target_endpoint, headers=browser_headers, timeout=10.0)
            
            if server_response.status != 200:
                print(f"Failed to fetch data for {symbol} (Status: {server_response.status})")
                continue

            try:
                xml_soup = BeautifulSoup(server_response.data, "lxml")
            except Exception:
                xml_soup = BeautifulSoup(server_response.data, "html.parser")
                
            scraped_titles = [item.title.text for item in xml_soup.find_all("item")][:10]

            if not scraped_titles:
                results[symbol] = {
                    "sentiment": "No headlines found",
                    "confidence": 0.0,
                    "sentiment_score_1_100": 50,
                    "headline_count": 0,
                    "breakdown": {"positive": 0, "negative": 0, "neutral": 0},
                }
                continue

            predictions = finbert(scraped_titles)
            sentiment_counts = {"positive": 0, "negative": 0, "neutral": 0}
            total_probs = {"positive": 0.0, "negative": 0.0, "neutral": 0.0}

            for preds in predictions:
                headline_top_label = max(preds, key=lambda x: x["score"])["label"]
                sentiment_counts[headline_top_label] += 1
                
                for pred in preds:
                    total_probs[pred["label"]] += pred["score"]

            num_headlines = len(scraped_titles)
            avg_pos = total_probs["positive"] / num_headlines
            avg_neg = total_probs["negative"] / num_headlines

            sentiment_1_100 = 50 + (avg_pos * 50) - (avg_neg * 49)
            sentiment_1_100 = max(1, min(100, round(sentiment_1_100)))

            dominant_sentiment = max(sentiment_counts, key=sentiment_counts.get)

            results[symbol] = {
                "sentiment": dominant_sentiment.capitalize(),
                "sentiment_score_1_100": sentiment_1_100,
                "headline_count": num_headlines,
                "breakdown": sentiment_counts,
            }

            time.sleep(1)

        except Exception as e:
            print(f"Error processing {symbol}: {e}")

    return results


if __name__ == "__main__":
    stock_list = ["AAPL", "TSLA", "NVDA", "GOOG", "MSFT"]
    sentiment_data = get_finbert_sentiment_rss_direct(stock_list)

    print("\n--- FinBERT Sentiment Results ---")
    for stock, data in sentiment_data.items():
        print(f"\n{stock}:")
        print(f"  Dominant Sentiment: {data['sentiment']}")
        print(f"  Composite Score (1-100): {data['sentiment_score_1_100']}")
        print(f"  Headlines Analyzed: {data['headline_count']}")
        print(f"  Distribution: {data['breakdown']}")
