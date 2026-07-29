# Comprehensive Trading Gate Reference

> **Purpose:** Normalized gate reference. Same feature = same name regardless of scanner/entry-finder version.

> **Generated:** 2026-07-28 | **Files analyzed:** 73 scanners + 74 entry finders

---

## Architecture

```
Universe ➜ 5m Scanner ➜ Signals ➜ 1m Entry Finder ➜ Entry ➜ TradeAlertWriter ➜ DB
                 └── 5m Gates ──┘          └──── 1m Gates ──────┘
```

---

## 1. FIVE-MINUTE SCANNER GATES (applied to 5m bars)

### 1.1 Master Gate Matrix

✓ = gate used. Number = default threshold. — = not used.

| Gate | V25.2 | V27.0 | V35.0 | V101.0 | V140.0 | V1600.0 | V200.0 | V300.0 | V400.0 | V600.0 | V700.0 | V900.x | V1100.0 | V3000.0 | V45 | V57.0 | UsesRedis |
|------|-------|-------|-------|--------|--------|---------|--------|--------|--------|--------|--------|--------|---------|---------|-----|-------|-----------|
| **notional** (price×vol ≥ T) | ✓ 75K | ✓ 30K | ✓ 75K | ✓ 100K | ✓ 100K | ✓ 75K | ✓ 250K | — | — | — | — | — | — | ✓ 75K | ✓ 75K | ✓ 50K | ✓ 75K |
| **price_floor** (price ≥ T) | — | ✓ $2 | — | — | ✓ $5 | — | ✓ $2.50 | — | — | — | — | ✓ | ✓ $5 | — | — | — | — |
| **price_ceiling** (price ≤ T) | — | — | — | — | — | — | ✓ $80 | — | — | — | — | — | ✓ $300 | — | — | — | — |
| **dollar_vol_per_minute** (price×vol/5 ≥ T) | — | — | — | — | — | — | — | — | — | — | — | — | ✓ 5K | — | — | — | — |
| **atr_pct_min** (ATR% ≥ T) | ✓ 0.35 | ✓ 0.15 | ✓ 0.35 | ✓ 0.45 | ✓ 0.40 | ✓ 0.35 | ✓ 0.15 | ✓ 0.15 | ✓ 2.0 | ✓ 0.25 | ✓ | — | — | ✓ 0.35 | ✓ 0.20 | — | ✓ 0.35 |
| **atr_pct_max** (ATR% ≤ T) | — | — | — | — | — | — | ✓ 2.50 | ✓ 2.50 | — | — | — | — | — | — | ✓ 2.00 | — | — |
| **rvol_ratio_min** (vol/avg ≥ T) | ✓ 2.0 | ✓ 1.2 | ✓ 2.0 | ✓ 1.5 | ✓ 1.5 | ✓ 2.0 | — | — | — | — | — | — | — | ✓ 2.0 | ✓ 1.05 | — | ✓ 2.0 |
| **rvol_ratio_max** (vol/avg ≤ T) | — | ✓ 10.0 | — | — | ✓ 5.0 | — | — | — | — | — | — | — | — | — | — | — | — |
| **activity_or** (rvol≥X **or** move≥Y) | ✓ | — | ✓ | ✓ | — | ✓ | — | — | — | — | — | — | — | ✓ | — | — | ✓ |
| **move_30m_pct** (30m change ≥ T) | ✓ 0.4 | ✓ 0.4 | ✓ 0.4 | ✓ | ✓ 1.5 | ✓ 0.4 | — | — | — | — | — | — | — | ✓ 0.4 | ✓ 0.30 | — | ✓ 0.4 |
| **move_30m_pct_max** (30m change ≤ T) | — | — | — | — | — | — | — | — | — | — | — | — | — | — | ✓ 6.0 | — | — |
| **move_from_open_pct** (day move ≥ T) | — | — | — | — | — | — | — | — | — | ✓ 0.80 | — | — | — | — | — | — | — |
| **net_progress_pct** (net % ≥ T) | — | — | — | — | — | — | — | — | — | — | ✓ | — | — | — | ✓ 0.05 | — | — |
| **above_vwap** (required) | — | — | — | ✓ | — | — | — | — | ✓ | — | ✓ | — | ✓ | — | ✓ | — | — |
| **max_above_vwap_pct** (vwap dist ≤ T) | — | — | — | — | — | — | ✓ 1.20 | — | — | ✓ 0.50 | — | — | ✓ | — | ✓ 3.0 | — | — |
| **vwap_distance_min** (dist from vwap ≥ T) | — | — | — | — | — | — | — | — | — | — | — | — | ✓ 0.15 | — | — | — | — |
| **ema9_above_ema21** (required) | — | — | — | ✓ | — | — | — | — | ✓ | — | — | — | ✓ | ✓ | — | — | — |
| **ema_spread_pct** ((ema9−ema21)/ema21 ≥ T) | — | — | — | — | — | — | — | — | — | — | — | — | ✓ 0.05 | — | — | — | — |
| **ema9_slope_positive** (ema9 rising) | — | — | — | — | — | — | — | — | ✓ | — | — | — | — | — | — | — | — |
| **distance_from_ema9_atr** (≤ N×ATR) | — | — | — | — | — | — | — | — | — | — | — | — | — | — | ✓ 2.5 | — | — |
| **green_bar_pct** (% green ≥ T) | — | — | — | — | — | — | — | — | — | — | — | — | — | — | ✓ 40 | — | — |
| **green_close** (close > open required) | — | — | — | — | — | — | — | — | — | — | — | — | ✓ | — | — | — | — |
| **multi_day_green_count** (N+ green days) | — | ✓ 1 | — | — | ✓ 3 | — | — | — | — | — | — | — | — | — | — | — | — |
| **yesterday_move_pct** (yest gain ≥ T) | — | — | — | — | — | — | — | — | — | ✓ 3.0 | — | ✓ | — | — | — | — | — |
| **yesterday_vol_mult** (yest vol ≥ avg×T) | — | — | — | — | — | — | — | — | — | ✓ 1.25 | — | — | — | — | — | — | — |
| **sum_vol_5m** (total 5m vol ≥ T) | — | — | — | — | — | — | ✓ 200K | — | — | — | — | — | — | — | — | — | — |
| **consolidation_range_pct** (tightness ≤ T) | — | — | — | — | — | — | ✓ 0.55 | — | — | — | — | — | — | — | — | — | — |
| **pullback_depth_pct** (within [min,max]) | — | — | — | — | — | — | — | — | ✓ 60 | — | — | — | — | — | ✓ 0-3 | — | — |
| **directional_changes_max** (≤ T) | — | — | — | — | — | — | — | — | — | — | — | — | — | — | ✓ 5 | — | — |
| **distance_from_high_atr** (≤ N×ATR) | — | — | — | — | — | — | — | — | — | — | — | — | ✓ 2.0 | — | — | — | — |
| **dist_to_hod_pct** (distance to HOD ≤ T) | — | — | — | — | — | — | — | — | — | ✓ 0.60 | — | — | — | — | — | — | — |
| **higher_low_count** (consecutive ≥ T) | — | — | — | — | — | — | — | — | ✓ 3 | — | — | — | — | — | — | — | — |
| **closes_near_high_count** (≥ T bars) | — | — | — | — | — | — | — | — | ✓ 5 | — | — | — | — | — | — | — | — |
| **vwap_violation_count** (= 0) | — | — | — | — | — | — | — | — | ✓ | — | — | — | — | — | — | — | — |
| **avg_green_vol_gt_red_vol** (×1.3) | — | — | — | — | — | — | — | — | ✓ | — | — | — | — | — | — | — | — |
| **range_contraction** (tightening) | — | — | — | — | — | — | — | — | — | — | — | — | ✓ | — | — | — | — |
| **rs_ratio** (move/benchmark ≥ T) | ✓ 1.20 | ✓ 1.02 | ✓ 1.20 | ✓ 1.05 | — | ✓ 1.10 | — | — | — | — | ✓ | — | ✓ 1.10 | ✓ 1.20 | — | — | ✓ |
| **market_weakness** (SPY 15m < 0) | — | — | — | — | — | — | — | — | — | — | — | — | ✓ | — | — | — | — |
| **benchmark_below_vwap** (required) | — | — | — | — | — | — | — | — | — | — | — | — | ✓ | — | — | — | — |
| **rsi_min** (≥ T) | — | — | — | — | — | — | — | — | — | — | ✓ | ✓ | — | — | — | — | — |
| **rsi_max** (≤ T) | — | — | — | — | — | — | — | — | — | — | ✓ | ✓ | — | — | — | — | — |
| **bb_position** (Bollinger pos) | — | — | — | — | — | — | — | — | — | — | — | ✓ | — | — | — | — | — |
| **signal_age_seconds** (≤ active×60) | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — | — | — | — | — | ✓ | — | ✓ | ✓ | ✓ | ✓ |
| **time_window** (market hours) | — | — | — | — | — | — | — | — | — | — | — | ✓ | — | — | — | ✓ | — |
| **opening_range_width_pct** (OR [min,max]) | — | — | — | — | — | — | — | — | — | — | — | — | — | — | — | ✓ | — |
| **opening_range_bar_count** (≥ T) | — | — | — | — | — | — | — | — | — | — | — | — | — | — | — | ✓ | — |
| **breakout_detected** (above OR high) | — | — | — | — | — | — | — | — | — | — | — | — | — | — | — | ✓ | — |
| **reversal_pattern** (B/D + reclaim) | — | — | — | — | — | — | — | ✓ | — | — | — | — | — | — | — | — | — |

### 1.2 Scoring Formulas (5m)

| Versions | Formula |
|----------|---------|
| V25.2, V35.0, V1600.0 | `(move30m × 1.2) + (min(6.0, rvol) × 1.0) + (atrPct × 0.8)` |
| V27.0 | `(move30m × 2.0) + (rvol × 1.0) + (atrPct × 1.0) + (greenDays × 2.0)` |
| V140.0 | `(move30m × 1.5) + (min(4.0, rvol) × 0.6) + (atrPct × 1.0) + (greenDays × 2.0)` |
| V400.0 | Component: vwap=25 + ema=20 + hh_hl=15 + support=15 + no_vwap_break=15 + demand=10 + vol=10 |

---

## 2. ONE-MINUTE ENTRY FINDER GATES (applied to 1m bars)

### 2.1 Master Gate Matrix

✓ = gate used. Number = default threshold. — = not used.

| Gate | V25.2 | V27.0 | V30.0 | V35.0 | V45 | V56.0 | V101.0 | V140.0 | V600.0 | V700.0 | V1600.0 | V3000.0 |
|------|-------|-------|-------|-------|-----|-------|--------|--------|--------|--------|---------|---------|
| **time_blocked** (lunch 11:30-13:30) | ✓ | ✓ | — | ✓ | — | — | ✓ | ✓ | — | — | ✓ | ✓ |
| **min_bars** (≥ T) | ✓ 90 | ✓ 15 | — | ✓ 15 | — | — | ✓ 15 | ✓ 20 | — | — | ✓ 15 | ✓ 15 |
| **max_entry_age_minutes** (≤ T) | ✓ 10 | ✓ 10 | — | ✓ 10 | — | — | ✓ 10 | ✓ 12 | — | — | ✓ 10 | ✓ 10 |
| **extreme_drop_pct** (bar drop ≤ −50%) | ✓ | ✓ | — | ✓ | — | — | ✓ | ✓ | — | — | ✓ | ✓ |
| **price_or_ema_invalid** (≤ 0) | — | — | ✓ | — | — | — | — | — | — | — | — | — |
| **notional_1m** (price×vol ≥ T) | ✓ 100K | ✓ 100K | — | ✓ 80K | ✓ 50K | ✓ 30K | ✓ 80K | ✓ 90K | — | — | ✓ 80K | ✓ 80K |
| **vol_ratio_1m_min** (vol/avg ≥ T) | ✓ 2.5 | ✓ 1.5 | ✓ 2.50 | ✓ 1.0 | — | ✓ 0.80 | ✓ 1.0 | ✓ 1.3 | ✓ 1.25 | ✓ | ✓ 1.0 | ✓ 1.0 |
| **vol_ratio_1m_max** (vol/avg ≤ T) | — | — | ✓ 6.0 | — | — | — | — | — | — | — | — | — |
| **body_pct_min** (|body|/range ≥ T) | ✓ 0.40 | ✓ 0.10 | — | ✓ 0.05 | ✓ 0.03 | ✓ 0.05 | ✓ 0.05 | ✓ 0.08 | — | — | ✓ 0.05 | ✓ 0.05 |
| **body_range_fraction_min** (body/range ≥ T) | — | — | — | — | — | ✓ 0.32 | — | — | — | — | — | — |
| **close_position_min** (close pos ≥ T) | — | — | — | — | ✓ 0.55 | ✓ 0.58 | — | — | — | — | — | — |
| **upper_wick_fraction_max** (wick ≤ T) | — | — | — | — | — | ✓ 0.40 | — | — | — | — | — | — |
| **above_vwap_entry_pct_max** (vwap dist ≤ T) | ✓ 0.60 | ✓ 0.75 | ✓ 0.25 | ✓ 0.90 | ✓ 1.25 | ✓ 3.50 | ✓ 0.90 | ✓ 1.00 | — | — | ✓ 0.90 | ✓ 1.20 |
| **above_vwap_required** (must be above) | — | — | — | — | ✓ | — | — | — | — | — | — | — |
| **room_to_run_pct_min** (≥ T) | ✓ 0.8 | ✓ 0.6 | — | ✓ 0.6 | ✓ 0.35 | — | ✓ 0.6 | ✓ 0.8 | — | — | ✓ 0.6 | ✓ 0.6 |
| **room_atr_mult** (ATR×T) | ✓ 2.5 | ✓ 1.5 | — | ✓ 1.5 | ✓ 1.5 | — | ✓ 1.5 | ✓ 1.8 | — | — | ✓ 1.5 | ✓ 1.5 |
| **ema9_above_ema21_1m** (required) | ✓ off | — | — | — | ✓ | ✓ | — | — | — | — | — | — |
| **ema9_above_ema21_5m** (required) | — | — | — | — | — | — | — | — | ✓ | — | — | — |
| **max_above_ema9_bps** (entry ≤ ema9+T) | — | — | ✓ 20 | — | — | — | — | — | — | — | — | — |
| **pullback_max_under_ema21_bps** (≤ T bps) | — | — | ✓ 35 | — | — | — | — | — | — | — | — | — |
| **pullback_depth_impulse_pct** (8-70% of impulse) | — | — | — | — | ✓ | — | — | — | — | — | — | — |
| **higher_low_pct_min** (≥ T) | — | — | — | — | ✓ 0.03 | — | — | — | — | — | — | — |
| **pullback_volume_ratio_max** (≤ T) | — | — | — | — | ✓ 1.00 | — | — | — | — | — | — | — |
| **pullback_bear_body_atr_max** (≤ N×ATR) | — | — | — | — | ✓ 0.85 | — | — | — | — | — | — | — |
| **pullback_vwap_dist_max** (close<vwap N%, dist<N%) | — | — | — | — | ✓ | — | — | — | — | — | — | — |
| **pullback_depth_5m_ema9_pct_max** (≤ T) | — | — | — | — | — | — | — | — | — | — | — | ✓ 0.4 |
| **reclaim_strength_pct_min** (green ≥ T) | — | — | — | — | — | — | — | — | — | — | — | ✓ 0.05 |
| **confirm_green** (green bar required) | — | — | — | — | ✓ | — | — | — | — | — | — | — |
| **confirm_high_break** (break prev high) | — | — | — | — | ✓ | — | — | — | — | — | — | — |
| **confirm_volume_ratio_min** (vol ≥ T) | — | — | — | — | ✓ 0.85 | — | — | — | — | — | — | — |
| **confirm_body_pct_min** (body ≥ T) | — | — | — | — | ✓ 0.03 | — | — | — | — | — | — | — |
| **confirm_close_position_min** (pos ≥ T) | — | — | — | — | ✓ 0.55 | — | — | — | — | — | — | — |
| **confirm_above_vwap_pct_max** (vwap ext ≤ T) | — | — | — | — | ✓ 1.25 | — | — | — | — | — | — | — |
| **impulse_move_pct_min** (≥ T) | — | — | — | — | ✓ 0.40 | — | — | — | — | — | — | — |
| **impulse_atr_min** (≥ T) | — | — | — | — | ✓ 0.90 | — | — | — | — | — | — | — |
| **impulse_green_required** | — | — | — | — | ✓ | — | — | — | — | — | — | — |
| **impulse_volume_ratio_min** (≥ T) | — | — | — | — | ✓ 0.80 | — | — | — | — | — | — | — |
| **trigger_high_break_volume_ratio** (≥ T) | — | — | — | — | — | ✓ 0.90 | — | — | — | — | — | — |
| **trigger_high_break_move_3m_pct** (≥ T) | — | — | — | — | — | ✓ 0.12 | — | — | — | — | — | — |
| **trigger_break_distance_atr_max** (≤ N×ATR) | — | — | — | — | — | ✓ 1.20 | — | — | — | — | — | — |
| **trigger_local_high_lookback** (bars) | — | — | — | — | — | ✓ 3 | — | — | — | — | — | — |
| **trigger_accel_move_3m_pct** (≥ T) | — | — | — | — | — | ✓ 0.22 | — | — | — | — | — | — |
| **trigger_accel_volume_ratio** (≥ T) | — | — | — | — | — | ✓ 0.80 | — | — | — | — | — | — |
| **max_hour** (hour cutoff) | — | — | — | — | — | — | — | — | ✓ 15 | ✓ | — | — |
| **choppiness_directional_max** (≤ T) | — | — | — | — | — | — | — | — | ✓ | ✓ 20 | — | — |
| **stop_buffer_bps** | — | — | ✓ 20 | — | — | — | — | — | — | — | — | — |
| **stop_atr_mult** | — | — | ✓ 2.0 | — | — | — | — | — | — | — | — | — |
| **stop_pct_min** (risk ≥ T) | — | — | ✓ 15 | — | ✓ 0.35 | — | — | — | — | — | — | — |
| **stop_pct_max** (risk ≤ T) | — | — | ✓ 80 | — | ✓ 1.00 | — | — | — | — | — | — | — |
| **reward_risk_min** (≥ T) | — | — | — | — | ✓ 1.50 | — | — | — | — | — | — | — |
| **score_min** (≥ T) | ✓ | — | ✓ 2.50 | — | — | ✓ 40 | — | — | ✓ 0.20 | — | — | — |

### 2.2 Notable Version Differences

**V25.2 (Pipeline H) — most aggressive defaults:**
- `min_bars` = 90 (6× others), `vol_ratio_1m_min` = 2.5, `body_pct_min` = 0.40, `room_atr_mult` = 2.5

**V30.0 (Pipeline B) — formal `aPlusGateCheck()` method.**

**V45 — Three-phase: Impulse → Pullback → Confirmation → Risk.**

**V56.0 — Trigger-specific gates per entry type.**

**V17.0 — Time-of-day vol thresholds: 09:30=1.3×, 10:30=1.2×, 11:30=1.0×, 14:00=1.2×**

**V21.0 — Williams Alligator, no traditional gates.**

---

## 3. REALTIME TRADING GATES

### 3.1 EarlyCandidateDetectorService

| Gate | Default |
|------|---------|
| `invalid_partial_bar` | close≤0 or open≤0 or vol≤0 |
| `price_too_low` | close < $5.00 |
| `dollar_volume_1m_too_low` | DB-backed |
| `atr_pct_too_low` | DB-backed (0.25%) |
| `rvol_too_low` | DB-backed (1.5) |
| `move_30m_too_low` | DB-backed (0.5%) |
| `too_far_above_vwap` | DB-backed (2.0%) |
| `no_hh_hl_structure` | HH/HL last 5 bars |
| `early_score_too_low` | < 65 |

### 3.2 RealtimeEntryTriggerService

| # | Gate | Description |
|----|------|-------------|
| 0 | `skip_first_minutes` | Skip N min after open |
| 1 | `candidate_too_old` | Age > N sec |
| 2 | `quote_too_old` | Age > N sec |
| 3 | `bad_bid_ask` | bid≤0 or ask≤0 or bid≥ask |
| 4 | `spread_too_wide` | Spread > max% |
| 5 | `price_out_of_range` | Price < min or > max |
| 6 | `invalid_partial_bar` | Zero/invalid data |
| 7 | `missing_vwap` | No VWAP |
| 8 | `too_far_above_vwap` | Extended above VWAP |
| 9 | `return_1m_too_low` | 1m return < T |
| 10 | `return_3m_too_low` | 3m return < T |
| 11 | `volume_ratio_too_low` | Vol ratio < T |
| 12 | `dollar_volume_1m_too_low` | $vol < T |
| 13 | `moved_too_far_since_candidate` | Upper bound |
| 14 | `price_dropped_below_candidate` | Lower bound |
| 15 | `weak_candle_close_position` | Close pos low |
| 16 | `upper_wick_too_large` | Wick large |
| 17 | `bid_ask_imbalance_too_weak` | Imbalance low |
| 18 | `ema9_not_above_ema21` | Not aligned |
| — | `final_score_too_low` | Score < T |

### 3.3 RealtimeEntryWatcherService

| Gate |
|------|
| `no_quote` |
| `invalid_price` |
| `moved_too_far` |
| `find_entry_null` |
| `entry_price_unavailable` |
| `moved_since_entry` |

---

## 4. PIPELINE MAPPING

| Pipeline | Scanner | Entry | Strategy | Redis |
|----------|---------|-------|----------|-------|
| A | V1100.0 | V1100.0 | Scarcity Leaders | ✓ |
| B | V120.0 | V120.0 | Elite Multi-Day | ✓ |
| C | V600.0 | V600.0 | Feasibility | ✓ |
| D | V60.3 | V60.3 | EntryScore | ✓ |
| E | V400.0 | V400.0 | Multi-Day Pattern | ✓ |
| F | V900.0 | V900.0 | Momentum Continuation | ✓ |
| G | V210.0 | V210.0 | EntryScore variation | ✓ |
| **H** | **V25.2** | **V25.2** | **Core Quality-First** | **✓** |
| I | V17.0 | V17.0 | Adaptive Volume | ✓ |
| J | V2000.0 | V2000.0 | Market Movers | ✓ |
| K | V1200.0 | V1200.0 | ORB / Price-Based | ✓ |
| L | V1600.0 | V1600.0 | Quality-First Extended | ✓ |
| M | V1400.0 | V1400.0 | Tight Stops | ✓ |
| N | V2000.1 | V2000.1 | Active Setup | ✓ |
| O | V1500.0 | V1500.0 | EntryScore variation | ✓ |
| P | V2100.0 | V2100.0 | Forward-Looking | ✗ |
| Q | V27.0 | V27.0 | Volume-First | ✓ |

---

## 5. UNIFIED REDIS SCANNER PLAN

### Core: One service computes ALL gates from the master tables using Redis bar data.

### 5m Gate Dimensions (for unified Redis scanner)
1. **Liquidity:** `notional`, `price_floor`, `price_ceiling`, `dollar_vol_per_minute`
2. **Volatility:** `atr_pct_min`, `atr_pct_max`
3. **Activity:** `rvol_ratio_min`, `rvol_ratio_max`, `activity_or`
4. **Momentum:** `move_30m_pct`, `move_30m_pct_max`, `move_from_open_pct`, `net_progress_pct`
5. **Structure:** `above_vwap`, `max_above_vwap_pct`, `ema9_above_ema21`, `ema_spread_pct`, `ema9_slope_positive`
6. **Multi-day:** `multi_day_green_count`, `yesterday_move_pct`, `yesterday_vol_mult`
7. **Pattern:** `consolidation_range_pct`, `pullback_depth_pct`, `directional_changes_max`, `range_contraction`, `higher_low_count`
8. **RS/Market:** `rs_ratio`, `market_weakness`, `benchmark_below_vwap`
9. **Time:** `signal_age_seconds`, `time_window`

### 1m Gate Dimensions (for unified Redis scanner)
1. **Liquidity:** `notional_1m`
2. **Volume:** `vol_ratio_1m_min`, `vol_ratio_1m_max`
3. **Candle:** `body_pct_min`, `close_position_min`, `upper_wick_fraction_max`
4. **VWAP:** `above_vwap_entry_pct_max`, `above_vwap_required`
5. **Room:** `room_to_run_pct_min`, `room_atr_mult`
6. **Trend:** `ema9_above_ema21_1m`, `ema9_above_ema21_5m`
7. **Anti-chase:** `max_above_ema9_bps`
8. **Time:** `time_blocked`, `min_bars`, `max_entry_age_minutes`
9. **Risk:** `stop_pct_min`, `stop_pct_max`, `reward_risk_min`

### Redis Keys
```
rt:bars:5m:{date}:stock:{symbol}      → Sorted set, 5m bars (epoch→JSON)
rt:bars:1m:{date}:stock:{symbol}      → Sorted set, 1m bars (epoch→JSON)
rt:config:gates:v25_2                 → Hash: gate_name→threshold
rt:gate:results:{symbol}:{ts}         → Hash: gate pass/fail per symbol
```
