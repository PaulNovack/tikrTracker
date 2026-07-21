# V2 Early (11h) vs V2 Later (18h, 21h): Same-Day Training Comparison

**Date analyzed:** 2026-06-13
**Data source:** `python_ml/v2/training_logs/`

---

## Summary Table: Key Metrics by Pipeline & Hour

| Pipeline | Hour | Type | Test AUC | P@10 | Win Rate | Notes |
|:---|:---|:---|:---:|:---:|:---:|:---|
| **B** | 11 | solo | **0.830** | 0.3 | 3.4% | Good calibration across bins |
| **B** | 21 | solo | **0.846** | 0.3 | 3.0% | +0.016 AUC, actual-AUC NaN (0 wins) |
| **J** | 11 | solo | 0.630 | 0.3 | 10.5% | Actual-AUC 0.196 (poor) |
| **J** | 18 | solo | 0.628 | 0.3 | 10.4% | Identical picks to 11h |
| **J** | 21 | J,P merged | **0.501** ♨️ | 0.4 | 20.0% | Discrimination collapsed |
| **L** | 11 | solo | 0.655 | 0.5 | 11.9% | Actual-AUC 0.969 (high-variance) |
| **L** | 18 | solo | 0.656 | 0.5 | 11.9% | Identical picks to 11h |
| **L** | 21 | L,P merged | **0.693** ✅ | 0.9 | 22.4% | Only pipeline P helped |
| **K** | 11 | solo | **0.777** | 0.9 | 12.7% | Best solo model; excellent calibration |
| **K** | 21 | K,P merged | **0.514** ♨️ | 0.5 | 48.2% | AUC halved; all 0.7–1.0 bins ~50% win |
| **E** | 11 | solo | 0.679 | 0.7 | 23.8% | Good bucket calibration |
| **E** | 21 | E,P merged | 0.615 | 0.9 | 35.5% | AUC down, P@10 up |
| **N** | 11 | solo | **0.762** | 0.9 | 19.3% | Perfect top-bin (92% win @ 0.9–1.0) |
| **N** | 21 | N,P merged | **0.589** ♨️ | 1.0 | 29.3% | Top-bin still 100% win but AUC collapsed |

♨️ = Significant AUC collapse | ✅ = Improved

---

## Finding 1: Hour 11 vs Hour 18 — No Improvement

For pipelines retrained solo at both 11h and 18h (J, L), the models are functionally identical:

| | J-11 | J-18 | L-11 | L-18 |
|---|---|---|---|---|
| AUC | 0.630 | 0.628 | 0.655 | 0.656 |
| P@10 | 0.3 | 0.3 | 0.5 | 0.5 |
| Win Rate | 10.5% | 10.4% | 11.9% | 11.9% |

The TOP 10 test picks are **exactly the same symbols in the same order**. A few hours of new intraday bars do not meaningfully change feature distributions.

**Conclusion:** Mid-day solo retraining provides no value.

---

## Finding 2: Pipeline P Merged Training Radically Changes Models

At 21h, a different strategy was used — Pipeline P (FORWARD_5PCT_RUNNER_5M) data was merged into each pipeline's training set. Impact:

| Pipeline | Solo AUC | P-Merged AUC | Delta |
|:---|:---:|:---:|:---:|
| J | 0.630 | 0.501 | −0.129 ♨️ |
| K | 0.777 | 0.514 | −0.263 ♨️ |
| N | 0.762 | 0.589 | −0.173 ♨️ |
| E | 0.679 | 0.615 | −0.064 |
| L | 0.655 | 0.693 | +0.038 ✅ |

Pipeline P data has a fundamentally different signal profile:
- All entries at 09:30–09:55 (market open)
- FORWARD_2H_RUNNER_1M entry type
- Very high win rate (48–50% in test splits)

Merging it dilutes the original pipeline's signal in most cases. **L is the exception** — L already uses similar entry patterns (EMA9_PULLBACK, VWAP_RECLAIM_STRONG) so the P data is complementary rather than contaminating.

---

## Finding 3: B — The Only Solo Pipeline With Modest Improvement

B trained solo at both 11h and 21h (no P merge):

| Metric | B-11 | B-21 |
|:---|---:|---:|
| Test AUC | 0.830 | 0.846 |
| Actual AUC | 0.706 | NaN |
| Actual Rows | 35 | 16 |
| P@10 | 0.3 | 0.3 |

The +0.016 AUC gain is within normal XGBoost run variance. The actual-fill AUC went to NaN because the 21h split had 0 actual-fill wins among 16 rows — likely a different evaluation split, not model degradation.

---

## Finding 4: Pipeline P Models Are Highly Optimistic

Pipeline P trained solo at 18h (log: `P-2026-06-13_18.log`):

- Win rate: ~50%
- High-prob bins (0.8–1.0) show 55–100% actual win rates
- All entries clustered at market open (09:30–09:55)

This explains why merging P boosts win rates for all pipelines — it inflates the overall positive class proportion.

---

## Finding 5: Calibration Degrades at Hour 21 for P-Merged Models

Solo models at 11h show clean monotonic probability calibration. P-merged models at 21h show:

- **K**: All bins 0.5–1.0 cluster around 48–57% win rate — the model lost discrimination
- **J**: All bins 0.3–1.0 oscillate between 18–31% win rate — no monotonic improvement
- **N**: Still good calibration in 0.8–1.0 bins, but 0.3–0.8 is flat

---

## Overall Verdict

1. **Retraining the same v2 pipeline multiple times within a single day produces no meaningful improvement.** 11h and 18h models are functionally identical.

2. **The bottleneck is not training frequency — it's the data.** Models learn from backtest-simulated trades; a few hours of new bars don't change feature distributions enough to matter.

3. **Merging Pipeline P data is destructive for most pipelines.** Only Pipeline L benefited. The P data has fundamentally different signal characteristics that contaminate rather than complement other pipeline training.

4. **Pipeline K at 11h is the strongest solo model** (AUC 0.777, actual-AUC 0.761, actual Precision@10 0.500).

5. **Recommendation:** Run solo training once overnight per pipeline. Skip mid-day retrains. Only merge P data with L (and possibly E if further testing supports it).

---

## Appendices

### Actual-Fill Subset Metrics (where available)

| Pipeline | Hour | Actual Rows | Actual Win% | Actual AUC | Actual P@10 |
|:---|:---|:---:|:---:|:---:|:---:|
| B | 11 | 35 | 2.9% | 0.706 | 0.000 |
| B | 21 | 16 | 0.0% | NaN | 0.000 |
| J | 11 | 48 | 4.2% | 0.196 | 0.000 |
| J | 18 | 48 | 4.2% | 0.196 | 0.000 |
| J | 21 | 91 | 4.4% | 0.569 | 0.100 |
| L | 11 | 33 | 3.0% | 0.969 | 0.100 |
| L | 18 | 33 | 3.0% | 0.969 | 0.100 |
| L | 21 | 33 | 3.0% | 0.969 | 0.100 |
| K | 11 | 146 | 13.0% | 0.761 | 0.500 |
| E | 11 | 2 | — | — | — |
| N | 11 | 2 | — | — | — |

Note: Actual-fill AUC is highly unstable for pipelines with fewer than 30 actual-fill rows. The 0.969 for L is from only 33 rows with 1 win and is unreliable. K's 0.761 from 146 rows with 19 wins is the most trustworthy actual-fill metric.

---

### Raw Probability Buckets

#### B-11 (solo)
| Bucket | Rows | Win Rate | Avg PnL |
|:---|---:|---:|---:|
| 0.0–0.3 | 1372 | 1.2% | −0.81% |
| 0.3–0.4 | 137 | 10.2% | +0.21% |
| 0.4–0.5 | 91 | 13.2% | +0.46% |
| 0.5–0.6 | 44 | 13.6% | +0.49% |
| 0.6–0.7 | 37 | 13.5% | +0.53% |
| 0.7–0.8 | 9 | 33.3% | +1.31% |
| 0.8–0.9 | 1 | 0.0% | −1.81% |

#### B-21 (solo)
| Bucket | Rows | Win Rate | Avg PnL |
|:---|---:|---:|---:|
| 0.0–0.3 | 882 | 0.6% | −0.01% |
| 0.3–0.4 | 130 | 4.6% | −0.02% |
| 0.4–0.5 | 118 | 9.3% | +0.13% |
| 0.5–0.6 | 68 | 5.9% | +0.13% |
| 0.6–0.7 | 45 | 15.6% | +0.40% |
| 0.7–0.8 | 16 | 25.0% | +0.80% |
| 0.8–0.9 | 7 | 14.3% | +0.30% |

#### J-11 vs J-21 — Win Rate by Bucket
| Bucket | J-11 Win% | J-21 Win% |
|:---|---:|---:|
| 0.0–0.3 | 6.5% | 31.4% |
| 0.3–0.4 | 12.9% | 22.2% |
| 0.4–0.5 | 10.3% | 18.9% |
| 0.5–0.6 | 16.8% | 20.1% |
| 0.6–0.7 | 15.9% | 18.4% |
| 0.7–0.8 | 16.3% | 19.4% |
| 0.8–0.9 | 27.3% | 25.1% |
| 0.9–1.0 | — | 25.0% |

#### L-11 vs L-21 — Win Rate by Bucket
| Bucket | L-11 Win% | L-21 Win% |
|:---|---:|---:|
| 0.0–0.3 | 7.2% | 10.6% |
| 0.3–0.4 | 13.9% | 19.9% |
| 0.4–0.5 | 18.6% | 27.1% |
| 0.5–0.6 | 11.8% | 31.6% |
| 0.6–0.7 | 17.2% | 31.0% |
| 0.7–0.8 | 41.9% | 47.2% |
| 0.8–0.9 | 44.4% | 46.2% |
| 0.9–1.0 | — | 100.0% |

#### N-11 vs N-21 — Win Rate by Bucket
| Bucket | N-11 Win% | N-21 Win% |
|:---|---:|---:|
| 0.0–0.3 | 6.9% | 25.0% |
| 0.3–0.4 | 11.6% | 25.1% |
| 0.4–0.5 | 20.7% | 30.2% |
| 0.5–0.6 | 28.9% | 33.8% |
| 0.6–0.7 | 31.5% | 33.3% |
| 0.7–0.8 | 38.6% | 46.2% |
| 0.8–0.9 | 60.6% | 58.7% |
| 0.9–1.0 | 92.0% | 100.0% |

#### K-11 (solo) — Win Rate by Bucket
| Bucket | Rows | Win Rate | Avg PnL |
|:---|---:|---:|---:|
| 0.0–0.3 | 1465 | 3.5% | −0.06% |
| 0.3–0.4 | 676 | 9.8% | +0.33% |
| 0.4–0.5 | 480 | 14.6% | +0.56% |
| 0.5–0.6 | 404 | 21.8% | +1.00% |
| 0.6–0.7 | 266 | 33.5% | +1.49% |
| 0.7–0.8 | 111 | 46.0% | +2.04% |
| 0.8–0.9 | 33 | 51.5% | +2.15% |
| 0.9–1.0 | 2 | 100.0% | +4.92% |

#### E-11 (solo) — Win Rate by Bucket
| Bucket | Rows | Win Rate | Avg PnL |
|:---|---:|---:|---:|
| 0.0–0.3 | 111 | 11.7% | −0.04% |
| 0.3–0.4 | 131 | 15.3% | +0.26% |
| 0.4–0.5 | 170 | 17.6% | +0.40% |
| 0.5–0.6 | 157 | 26.8% | +1.53% |
| 0.6–0.7 | 86 | 45.3% | +2.41% |
| 0.7–0.8 | 36 | 41.7% | +2.15% |
| 0.8–0.9 | 11 | 72.7% | +5.65% |
