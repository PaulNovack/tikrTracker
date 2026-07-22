import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Badge } from '@/components/ui/badge';
import { show as showAsset } from '@/routes/asset-info';
import { BarChart3, CandlestickChart, Clock, Loader2, TrendingDown, TrendingUp } from 'lucide-react';
import { useState, useEffect } from 'react';
import {
    ComposedChart,
    Bar,
    Line,
    XAxis,
    YAxis,
    CartesianGrid,
    Tooltip,
    ResponsiveContainer,
    Cell,
} from 'recharts';

interface OHLCBar {
    date: string;
    open: number;
    high: number;
    low: number;
    close: number;
    volume: number;
}

interface ScanResult {
    symbol: string;
    asset_id: number;
    signal: 'bullish' | 'bearish';
    signal_value: number;
    last_date: string;
    ohlc: OHLCBar[];
}

interface ScanResponse {
    pattern: string;
    pattern_name: string;
    total_scanned: number;
    hits: number;
    results: ScanResult[];
}

interface Props {
    patterns: Record<string, string>;
    selectedPattern: string;
    results: ScanResponse | null;
    limit: number;
    error: string | null;
}

function MiniCandlestickChart({ data, signal }: { data: OHLCBar[]; signal: 'bullish' | 'bearish' }) {
    const chartData = data.map((bar) => ({
        ...bar,
        fill: bar.close >= bar.open ? '#22c55e' : '#ef4444',
        volFill: bar.close >= bar.open ? '#4ade80' : '#f87171',
    }));

    const prices = data.flatMap((d) => [d.high, d.low]);
    const minPrice = Math.min(...prices);
    const maxPrice = Math.max(...prices);
    const padding = (maxPrice - minPrice) * 0.05 || 1;

    return (
        <div className="h-[200px] w-full">
            <ResponsiveContainer width="100%" height="100%">
                <ComposedChart data={chartData} margin={{ top: 4, right: 4, bottom: 4, left: 4 }}>
                    <CartesianGrid strokeDasharray="3 3" stroke="#374151" />
                    <XAxis dataKey="date" hide />
                    <YAxis domain={[minPrice - padding, maxPrice + padding]} hide />
                    <Tooltip
                        contentStyle={{ backgroundColor: '#1f2937', border: '1px solid #374151', borderRadius: '8px' }}
                        labelStyle={{ color: '#9ca3af' }}
                        formatter={(value: number, name: string) => [value.toFixed(2), name]}
                    />
                    <Bar dataKey="close" fill="#22c55e" barSize={4}>
                        {chartData.map((entry, idx) => (
                            <Cell key={`cell-${idx}`} fill={entry.fill} />
                        ))}
                    </Bar>
                    <Line dataKey="high" stroke="#9ca3af" dot={false} strokeWidth={1} />
                    <Bar dataKey="volume" fill="#4b5563" opacity={0.3} barSize={2} yAxisId="volume" />
                    <YAxis yAxisId="volume" hide domain={[0, (max: number) => max * 4]} />
                </ComposedChart>
            </ResponsiveContainer>
        </div>
    );
}

type PatternInfo = {
    title: string;
    subtitle: string;
    description: string;
    structure: string[];
    interpretation: string[];
    confirmation: string;
};

const PATTERN_DESCRIPTIONS: Record<string, PatternInfo> = {
    CDL2CROWS: {
        title: 'Two Crows',
        subtitle: 'A bearish reversal pattern',
        description:
            'The Two Crows pattern is a three-candle bearish reversal that appears at the top of an uptrend. It features a long white candle followed by a gap up and a black candle that erases gains, then a second black candle that engulfs the first.',
        structure: [
            'First candle: long white (bullish) candle in an established uptrend',
            'Second candle: black candle that gaps higher but closes lower',
            'Third candle: black candle that engulfs the second and closes lower',
            'The second and third candles (the "crows") should not fill the gap fully',
        ],
        interpretation: [
            'Signals exhaustion of the uptrend as sellers take control',
            'The gap up followed by selling shows smart money distribution',
            'The third candle confirms bears have overwhelmed bulls',
            'Most reliable after a prolonged advance',
        ],
        confirmation:
            'Traders typically wait for a close below the pattern low or a break of support before entering short positions.',
    },
    CDL3BLACKCROWS: {
        title: 'Three Black Crows',
        subtitle: 'A strong bearish reversal pattern',
        description:
            'The Three Black Crows pattern consists of three consecutive long-bodied black (red) candles that form at the top of an uptrend. Each candle opens within the previous candle\'s real body and closes at or near its low, signaling a decisive shift from bullish to bearish sentiment.',
        structure: [
            'Three consecutive long-bodied bearish candles',
            'Each candle opens within the prior candle\'s real body',
            'Each candle closes at or near its session low',
            'Little to no lower shadows — sustained selling all session',
            'Volume should increase through the pattern',
        ],
        interpretation: [
            'The first black candle suggests buyers are losing control',
            'The second confirms bears are now in charge',
            'The third signals overwhelming selling pressure',
            'Opposite of Three White Soldiers — marks a potential top',
        ],
        confirmation:
            'Traders often wait for a lower close or breakdown below the third candle\'s lows on the next bar before shorting.',
    },
    CDL3INSIDE: {
        title: 'Three Inside Up/Down',
        subtitle: 'A three-candle reversal pattern',
        description:
            'The Three Inside pattern extends the Harami formation. After a Harami (small candle inside the prior candle\'s range), a third candle closes beyond the first candle\'s range, confirming the reversal.',
        structure: [
            'First candle: long bullish or bearish candle in the direction of the trend',
            'Second candle: small-bodied candle contained within the first candle\'s range (Harami)',
            'Third candle: closes above (up) or below (down) the first candle, confirming reversal',
        ],
        interpretation: [
            'The Harami (candle 2) signals indecision and a potential shift',
            'The third candle provides the confirmation that the trend has reversed',
            'Three Inside Up is bullish; Three Inside Down is bearish',
        ],
        confirmation:
            'Confirmation comes from the third candle itself. Additional confirmation is a close beyond the third candle\'s high/low.',
    },
    CDL3LINESTRIKE: {
        title: 'Three-Line Strike',
        subtitle: 'A continuation pattern',
        description:
            'The Three-Line Strike is a four-candle continuation pattern. Three candles move in the trend direction, followed by a large counter-trend candle that reverses all three moves — confirming the original trend resumes.',
        structure: [
            'Three candles in the direction of the prevailing trend',
            'Fourth candle: large opposite-color candle that engulfs the previous three',
            'In a bullish strike, three white candles are followed by one large black engulfing candle',
        ],
        interpretation: [
            'The fourth candle may look bearish but is actually a continuation signal',
            'Represents a shakeout — weak hands are flushed out before the trend resumes',
            'Common in strong trending markets',
        ],
        confirmation:
            'Traders look for price to move above the fourth candle\'s high (bullish) or below its low (bearish) to confirm continuation.',
    },
    CDL3OUTSIDE: {
        title: 'Three Outside Up/Down',
        subtitle: 'A strong reversal pattern',
        description:
            'The Three Outside pattern starts with an Engulfing candle (candle 2 engulfs candle 1), followed by a third candle that closes beyond the engulfing candle\'s range, confirming the reversal.',
        structure: [
            'First candle: small-bodied candle in the trend direction',
            'Second candle: large opposite-color candle that completely engulfs the first',
            'Third candle: closes further in the reversal direction',
        ],
        interpretation: [
            'The engulfing candle shows aggressive counter-trend buying/selling',
            'The third candle confirms that the reversal has staying power',
            'Three Outside Up is bullish; Three Outside Down is bearish',
        ],
        confirmation:
            'The third candle serves as confirmation. Traders may wait for follow-through beyond the third candle.',
    },
    CDL3STARSINSOUTH: {
        title: 'Three Stars In The South',
        subtitle: 'A rare bullish reversal pattern',
        description:
            'A rare three-candle pattern forming at the bottom of a downtrend. Each candle has a smaller range than the previous, with progressively higher lows — suggesting selling pressure is dissipating.',
        structure: [
            'First candle: long black candle with a lower shadow',
            'Second candle: similar to first but with a narrower range and higher low',
            'Third candle: small-bodied, contained within the second candle\'s range',
        ],
        interpretation: [
            'Each candle shows less conviction from sellers',
            'The narrowing ranges indicate a potential bottom forming',
            'Volume should decline through the pattern',
            'Very rare — significant when it appears',
        ],
        confirmation:
            'Traders look for a strong bullish candle above the third candle\'s high to confirm the reversal.',
    },
    CDLABANDONEDBABY: {
        title: 'Abandoned Baby',
        subtitle: 'A rare and powerful reversal pattern',
        description:
            'The Abandoned Baby is a rare three-candle reversal pattern. A doji gaps away from the prior candle, then the third candle gaps in the opposite direction and closes well into the first candle\'s body. When bullish, it\'s a Morning Doji Star with gaps on both sides of the doji.',
        structure: [
            'First candle: long candle in the trend direction',
            'Second candle: doji that gaps above (bearish) or below (bullish) the first',
            'Third candle: gaps opposite the doji and closes into the first candle\'s body',
            'The doji\'s shadow must not overlap with the first candle\'s shadow',
        ],
        interpretation: [
            'Signals an abrupt change in sentiment — the trend has been "abandoned"',
            'The doji represents complete indecision after a gap',
            'Bullish Abandoned Baby is extremely rare and powerfully bullish',
            'Bearish Abandoned Baby appears at tops',
        ],
        confirmation:
            'Since the third candle itself gaps away from the doji, confirmation is immediate. Traders place stops beyond the doji.',
    },
    CDLADVANCEBLOCK: {
        title: 'Advance Block',
        subtitle: 'A weakening bullish pattern',
        description:
            'The Advance Block appears in an uptrend. Three white candles with progressively smaller bodies and longer upper shadows — suggesting the uptrend is running out of steam.',
        structure: [
            'Three consecutive white candles',
            'Bodies get progressively smaller',
            'Upper shadows lengthen with each candle',
            'Pattern appears in an established uptrend',
        ],
        interpretation: [
            'Shows buyers are losing momentum despite pushing higher',
            'Long upper shadows indicate sellers are stepping in at higher levels',
            'A warning sign, not a direct entry signal',
            'Often precedes a pullback or reversal',
        ],
        confirmation:
            'Traders watch for a close below the third candle\'s body or a bearish engulfing candle to confirm the reversal.',
    },
    CDLBELTHOLD: {
        title: 'Belt-hold',
        subtitle: 'A single-candle reversal',
        description:
            'The Belt-hold is a single long candle that opens at (or near) its high (bearish) or low (bullish) and closes at the opposite extreme. A bullish belt-hold opens near its low and closes near its high; a bearish belt-hold opens near its high and closes near its low.',
        structure: [
            'Single long-bodied candle',
            'Opens at or very near the high (bearish) or low (bullish)',
            'No shadow at the open, significant shadow at the close is acceptable',
        ],
        interpretation: [
            'Bullish Belt-hold: price opens low and buyers immediately take control all session',
            'Bearish Belt-hold: price opens high and sellers dominate from the start',
            'More significant when it follows a clear trend',
        ],
        confirmation:
            'Traders typically wait for the next candle to confirm — a higher close for bullish, lower close for bearish.',
    },
    CDLBREAKAWAY: {
        title: 'Breakaway',
        subtitle: 'A five-candle trend reversal',
        description:
            'The Breakaway is a five-candle reversal pattern. After a long trending candle, the next three candles move slightly against the trend (with small bodies), then a fifth candle breaks strongly in the opposite direction, beginning a new trend.',
        structure: [
            'First candle: long candle in the trend direction',
            'Second, third, fourth candles: small-bodied, slowly moving against the trend',
            'Fifth candle: long opposite-color candle breaking past the first candle\'s close',
        ],
        interpretation: [
            'The small candles show the trend losing steam',
            'The fifth candle breaks out with conviction — a new trend begins',
            'Reliable when volume expands on the fifth candle',
        ],
        confirmation:
            'The fifth candle itself is the breakout. Traders may wait for a retest of the breakout level before entering.',
    },
    CDLCLOSINGMARUBOZU: {
        title: 'Closing Marubozu',
        subtitle: 'A single-candle continuation signal',
        description:
            'A Closing Marubozu is a long candle that closes at its extreme but has a small shadow at the open — showing that after a brief hesitation at the open, one side controlled the entire session.',
        structure: [
            'Long-bodied candle',
            'Small shadow at the open, no shadow at the close',
            'White (bullish) closes at high; black (bearish) closes at low',
        ],
        interpretation: [
            'Shows overwhelming dominance by buyers (white) or sellers (black)',
            'Indicates strong momentum in the candle\'s direction',
            'More significant when it breaks a previous support/resistance level',
        ],
        confirmation:
            'Traders look for follow-through in the next candle — continuation above the high (bullish) or below the low (bearish).',
    },
    CDLCONCEALBABYSWALL: {
        title: 'Concealing Baby Swallow',
        subtitle: 'A rare bearish reversal pattern',
        description:
            'A four-candle bearish reversal. Two black Marubozu candles are followed by a third black Marubozu that gaps down — its upper shadow penetrates the prior candle\'s body, then a fourth black Marubozu engulfs the third, "swallowing" the baby bear candle.',
        structure: [
            'First and second: two black Marubozu candles in a downtrend',
            'Third: black Marubozu that gaps down, with upper shadow into the second',
            'Fourth: black Marubozu that completely engulfs the third candle',
        ],
        interpretation: [
            'Very rare pattern that appears in aggressive downtrends',
            'The "swallowing" suggests sellers remain in absolute control',
            'Despite the gapped third candle, the fourth shows continued dominance',
        ],
        confirmation:
            'Traders look for continued bearish follow-through below the fourth candle\'s low.',
    },
    CDLCOUNTERATTACK: {
        title: 'Counterattack',
        subtitle: 'A two-candle reversal',
        description:
            'The Counterattack pattern consists of two opposite-color candles with the same close price. After a trend, a strong opposite candle closes exactly at the prior candle\'s close — showing that counter-trend forces have neutralized the move.',
        structure: [
            'First candle: long candle in the trend direction',
            'Second candle: long opposite-color candle with the same close',
            'Both candles have similar body sizes',
        ],
        interpretation: [
            'Shows a tug-of-war where neither side wins at the close',
            'The second candle has fully offset the first candle\'s progress',
            'Bullish Counterattack appears in a downtrend; bearish in an uptrend',
        ],
        confirmation:
            'Traders wait for a third candle to close beyond the second candle\'s range to confirm the reversal.',
    },
    CDLDARKCLOUDCOVER: {
        title: 'Dark Cloud Cover',
        subtitle: 'A bearish reversal at the top',
        description:
            'Dark Cloud Cover is a two-candle bearish reversal. After a strong white candle, a black candle opens above the prior high but closes deep into the prior candle\'s body — ideally below the midpoint — darkening the bullish outlook.',
        structure: [
            'First candle: long white candle in an uptrend',
            'Second candle: opens above the first candle\'s high',
            'Second candle closes at least halfway into the first candle\'s body',
        ],
        interpretation: [
            'The gap up creates euphoria, but sellers immediately step in',
            'The deeper the second candle penetrates the first, the stronger the signal',
            'Signals that buyers are trapped and sellers are taking control',
        ],
        confirmation:
            'Traders look for a close below the second candle\'s low on the next bar to confirm.',
    },
    CDLDOJI: {
        title: 'Doji',
        subtitle: 'A single-candle indecision signal',
        description:
            'A Doji forms when the open and close are virtually equal, creating a cross or plus-sign shape. It represents indecision — neither buyers nor sellers could move price meaningfully by the close.',
        structure: [
            'Open and close are at or extremely near the same price',
            'Upper and lower shadows can vary in length',
            'The real body is virtually nonexistent',
        ],
        interpretation: [
            'Signals market indecision and potential trend change',
            'More significant after a strong trending move',
            'A Doji after a long white candle suggests buyers are losing conviction',
            'A Doji after a long black candle suggests sellers are exhausted',
        ],
        confirmation:
            'Traders wait for the next candle to break above (bullish) or below (bearish) the doji to confirm direction.',
    },
    CDLDOJISTAR: {
        title: 'Doji Star',
        subtitle: 'A two-candle reversal setup',
        description:
            'A Doji Star consists of a long trending candle followed by a doji that gaps in the trend direction. The doji signals that momentum has stalled and a reversal may be imminent.',
        structure: [
            'First candle: long candle in the trend direction',
            'Second candle: doji that gaps above (uptrend) or below (downtrend)',
        ],
        interpretation: [
            'The gap shows one final push in the trend direction, but the doji shows it failed',
            'After a long uptrend, a Doji Star suggests buying pressure is exhausted',
            'After a long downtrend, a Doji Star suggests selling is drying up',
        ],
        confirmation:
            'Traders wait for a third candle to close back into the first candle\'s body to confirm the reversal.',
    },
    CDLENGULFING: {
        title: 'Engulfing Pattern',
        subtitle: 'A powerful two-candle reversal',
        description:
            'The Engulfing pattern occurs when a large opposite-color candle completely engulfs the prior candle\'s real body. A bullish engulfing engulfs a prior black candle; a bearish engulfing engulfs a prior white candle.',
        structure: [
            'First candle: small-bodied candle in the trend direction',
            'Second candle: large opposite-color candle that completely engulfs the first',
            'The second candle\'s body must fully cover the first candle\'s body',
        ],
        interpretation: [
            'Bullish Engulfing: after a downtrend, a large white candle swallows the prior black candle',
            'Bearish Engulfing: after an uptrend, a large black candle swallows the prior white candle',
            'Shows a dramatic shift in control from one side to the other',
            'Stronger when the engulfing candle also engulfs the shadows',
        ],
        confirmation:
            'Traders often enter on the close of the engulfing candle itself, placing stops beyond the engulfing candle\'s range.',
    },
    CDLEVENINGDOJISTAR: {
        title: 'Evening Doji Star',
        subtitle: 'A bearish three-candle reversal',
        description:
            'The Evening Doji Star is a three-candle bearish reversal at the top of an uptrend. A long white candle, followed by a doji that gaps up, then a long black candle that closes deep into the first candle — the bearish counterpart to the Morning Doji Star.',
        structure: [
            'First candle: long white candle in an uptrend',
            'Second candle: doji that gaps above the first',
            'Third candle: long black candle closing well into the first candle\'s body',
        ],
        interpretation: [
            'The doji shows that after gapping up, buyers could not push higher',
            'The third black candle confirms sellers have taken control',
            'Named "Evening" because it signals the end of the bullish "day"',
        ],
        confirmation:
            'The third candle itself serves as confirmation. Stops are placed above the doji\'s high.',
    },
    CDLEVENINGSTAR: {
        title: 'Evening Star',
        subtitle: 'A bearish three-candle reversal',
        description:
            'The Evening Star is the bearish counterpart to the Morning Star. After an uptrend, a long white candle is followed by a small-bodied candle that gaps up, then a long black candle closes deep into the first candle\'s body.',
        structure: [
            'First candle: long white candle continuing the uptrend',
            'Second candle: small-bodied candle (star) that gaps above the first',
            'Third candle: long black candle closing below the first candle\'s midpoint',
            'Volume increases on the third candle',
        ],
        interpretation: [
            'The star shows buying momentum has stalled',
            'The third black candle proves sellers now dominate',
            'Signals the uptrend is ending — "evening" has come for the bulls',
        ],
        confirmation:
            'Traders look for a close below the third candle or follow-through selling in the next bar.',
    },
    CDLGAPSIDESIDEWHITE: {
        title: 'Up/Down-gap Side-by-Side White Lines',
        subtitle: 'A continuation pattern',
        description:
            'Two white candles appear side by side after a gap — in an uptrend this is bullish continuation (Up-gap), in a downtrend this is bearish continuation (Down-gap).',
        structure: [
            'First candle: candle that creates a gap',
            'Second and third: two white candles at roughly the same price level',
            'In an uptrend, the gap is up; in a downtrend, the gap is down',
        ],
        interpretation: [
            'In an uptrend, the two white lines show that buying continues at the higher level',
            'In a downtrend, the two white lines are a brief pause before further decline',
        ],
        confirmation:
            'Traders wait for price to move above the two white lines (bullish) or below (bearish) to confirm.',
    },
    CDLGRAVESTONEDOJI: {
        title: 'Gravestone Doji',
        subtitle: 'A bearish reversal signal',
        description:
            'The Gravestone Doji is the inverse of the Dragonfly Doji. Open, low, and close are at or near the same price with a long upper shadow — resembling a gravestone. It signals a potential top.',
        structure: [
            'Open, low, and close at or near the same level',
            'Little to no lower shadow',
            'Long upper shadow',
            'Most significant at the top of an uptrend',
        ],
        interpretation: [
            'Buyers pushed price significantly higher during the session',
            'Sellers aggressively drove price back down to the open by the close',
            'The long upper shadow represents rejection of higher prices',
            'A bearish signal, especially after an extended advance',
        ],
        confirmation:
            'Traders typically wait for a follow-up red confirmation candle closing lower before executing trades.',
    },
    CDLHAMMER: {
        title: 'Hammer',
        subtitle: 'A bullish reversal at the bottom',
        description:
            'The Hammer is a single-candle bullish reversal with a small real body at the upper end of the session and a long lower shadow — at least twice the body length. It forms at the bottom of a downtrend, signaling sellers were rejected.',
        structure: [
            'Small real body at the upper end of the session',
            'Long lower shadow — at least 2x the body length',
            'Little or no upper shadow',
            'Appears at the bottom of a downtrend',
        ],
        interpretation: [
            'Sellers pushed price down significantly during the session',
            'Buyers stepped in aggressively and drove price back up',
            'The long lower shadow shows rejection of lower prices',
            'The close near the high shows buyers finished in control',
        ],
        confirmation:
            'Traders wait for a higher close in the next candle to confirm the reversal before entering long.',
    },
    CDLHANGINGMAN: {
        title: 'Hanging Man',
        subtitle: 'A bearish reversal at the top',
        description:
            'The Hanging Man looks identical to a Hammer but appears at the top of an uptrend. It has a small body at the upper end with a long lower shadow — warning that selling pressure is emerging.',
        structure: [
            'Small real body at the upper end of the session',
            'Long lower shadow — at least 2x the body length',
            'Little or no upper shadow',
            'Appears at the top of an uptrend (this is the key difference from a Hammer)',
        ],
        interpretation: [
            'Despite the uptrend, sellers pushed price sharply lower during the session',
            'Buyers managed to drive price back up, but the dip is a warning',
            'The long lower shadow shows distribution — smart money may be selling',
            'More reliable when confirmed by a gap down or black candle the next day',
        ],
        confirmation:
            'Traders wait for a close below the Hanging Man\'s body or a gap down to confirm the reversal.',
    },
    CDLHARAMI: {
        title: 'Harami Pattern',
        subtitle: 'A two-candle reversal',
        description:
            'Harami ("pregnant" in Japanese) is a two-candle reversal pattern. A small-bodied candle is completely contained within the prior long candle\'s real body. Bullish Harami appears in a downtrend; Bearish Harami appears in an uptrend.',
        structure: [
            'First candle: long candle in the trend direction',
            'Second candle: small-bodied candle fully within the first candle\'s body',
            'Shadows may extend beyond, but the body must be contained',
        ],
        interpretation: [
            'The small second candle shows the trend is losing momentum',
            'Bullish Harami: after a downtrend, selling pressure is drying up',
            'Bearish Harami: after an uptrend, buying pressure is fading',
            'The smaller the second candle, the stronger the signal',
        ],
        confirmation:
            'Traders wait for a close beyond the Harami\'s range — above for bullish, below for bearish.',
    },
    CDLHARAMICROSS: {
        title: 'Harami Cross Pattern',
        subtitle: 'A stronger Harami with a doji',
        description:
            'The Harami Cross is a more powerful version of the Harami where the second candle is a doji rather than just a small body. The doji within the prior candle signals even greater indecision.',
        structure: [
            'First candle: long candle in the trend direction',
            'Second candle: doji fully within the first candle\'s body',
        ],
        interpretation: [
            'The doji represents complete indecision after a strong trending move',
            'More significant than a regular Harami due to the doji',
            'Bullish Harami Cross: doji after a long black candle in a downtrend',
            'Bearish Harami Cross: doji after a long white candle in an uptrend',
        ],
        confirmation:
            'Traders wait for a close above (bullish) or below (bearish) the Harami Cross range.',
    },
    CDLHIGHWAVE: {
        title: 'High-Wave Candle',
        subtitle: 'An indecision candle with long shadows',
        description:
            'A High-Wave candle has a small real body with both long upper and lower shadows — showing a volatile session where neither side could gain control. It signals indecision.',
        structure: [
            'Small real body near the middle of the session range',
            'Both upper and lower shadows are significantly longer than the body',
            'Can appear in any trend',
        ],
        interpretation: [
            'Both buyers and sellers pushed price far, only to be rejected',
            'Signals extreme indecision and potential trend change',
            'More meaningful when it follows a strong trend',
        ],
        confirmation:
            'Traders wait for the next candle to close above the high or below the low of the High-Wave candle.',
    },
    CDLHIKKAKE: {
        title: 'Hikkake Pattern',
        subtitle: 'A false breakout trap',
        description:
            'The Hikkake is a trap pattern. Price appears to break out but immediately reverses, trapping breakout traders. A bullish Hikkake fakes a downside break before reversing up; a bearish Hikkake fakes an upside break before reversing down.',
        structure: [
            'An inside bar (candle within prior candle\'s range)',
            'A fake breakout above or below the inside bar',
            'Price reverses and trades back through the inside bar',
        ],
        interpretation: [
            'The fake breakout traps traders who entered on the break',
            'The reversal punishes those trapped and fuels the real move',
            'Often leads to strong counter-moves',
        ],
        confirmation:
            'Confirmation comes when price closes beyond the inside bar in the opposite direction of the fake breakout.',
    },
    CDLHIKKAKEMOD: {
        title: 'Modified Hikkake Pattern',
        subtitle: 'A variation of the Hikkake trap',
        description:
            'The Modified Hikkake relaxes the inside bar requirement, allowing the pattern to form with any candle followed by a false breakout and reversal.',
        structure: [
            'A candle followed by a false breakout above or below',
            'Price reverses and closes back through the prior candle\'s range',
        ],
        interpretation: [
            'Similar to the standard Hikkake but with more flexibility in candle shapes',
            'The key element is the false breakout and reversal',
            'More common than the standard Hikkake',
        ],
        confirmation:
            'Confirmation comes when price closes beyond the initial candle in the direction opposite the false break.',
    },
    CDLHOMINGPIGEON: {
        title: 'Homing Pigeon',
        subtitle: 'A bullish continuation in a downtrend',
        description:
            'The Homing Pigeon is a two-candle pattern in a downtrend. A long black candle is followed by a smaller black candle fully within the first — suggesting selling pressure is waning and a pause or reversal may come.',
        structure: [
            'First candle: long black candle in a downtrend',
            'Second candle: smaller black candle fully within the first',
        ],
        interpretation: [
            'Despite both candles being bearish, the smaller second candle shows weakening momentum',
            'Like a Harami but both candles are the same color',
            'Suggests the downtrend is losing energy',
        ],
        confirmation:
            'Traders look for a close above the second candle\'s high or a white candle to confirm a bullish reversal.',
    },
    CDLIDENTICAL3CROWS: {
        title: 'Identical Three Crows',
        subtitle: 'An extremely rare bearish pattern',
        description:
            'A rare variant of Three Black Crows where all three candles open at the prior candle\'s close — creating a "stair-step" descent. Extremely bearish when it appears.',
        structure: [
            'Three consecutive black candles',
            'Each candle opens exactly at the prior candle\'s close',
            'All three candles are black with no lower shadows',
        ],
        interpretation: [
            'Shows relentless, methodical selling with no bounces',
            'Each candle gives no reprieve to buyers',
            'Very rare — significant when found',
        ],
        confirmation:
            'The pattern itself is confirmation of severe selling pressure. Traders may short on the close of the third candle.',
    },
    CDLINNECK: {
        title: 'In-Neck Pattern',
        subtitle: 'A bearish continuation',
        description:
            'The In-Neck pattern appears in a downtrend. A long black candle is followed by a white candle that gaps down but closes only slightly into the prior black candle\'s body (at the close of the black candle, or "in the neck").',
        structure: [
            'First candle: long black candle in a downtrend',
            'Second candle: white candle that opens below the first but closes near its close',
        ],
        interpretation: [
            'The bounce is weak — buyers couldn\'t push past the prior close',
            'Suggests sellers are still in control and the downtrend will continue',
            'Different from a Piercing pattern because the close is at the neck, not deep into the body',
        ],
        confirmation:
            'Traders wait for a close below the second candle\'s low to confirm continuation of the downtrend.',
    },
    CDLINVERTEDHAMMER: {
        title: 'Inverted Hammer',
        subtitle: 'A bullish reversal at the bottom',
        description:
            'The Inverted Hammer has a small real body at the lower end with a long upper shadow. It appears at the bottom of a downtrend, signaling that buyers attempted to push higher and may succeed on the next attempt.',
        structure: [
            'Small real body at the lower end of the session',
            'Long upper shadow — at least 2x the body length',
            'Little or no lower shadow',
            'Appears at the bottom of a downtrend',
        ],
        interpretation: [
            'Buyers pushed price significantly higher but were partially rejected',
            'The attempt shows emerging buying interest',
            'Confirmation is critical — the next candle must close above the Inverted Hammer',
        ],
        confirmation:
            'Traders need a higher close in the next candle to confirm — without it, the pattern fails.',
    },
    CDLKICKING: {
        title: 'Kicking',
        subtitle: 'A powerful two-candle reversal',
        description:
            'Kicking consists of two Marubozu candles of opposite colors that gap apart. Bullish Kicking: black Marubozu opens lower then a white Marubozu gaps up. Bearish Kicking: the reverse.',
        structure: [
            'First candle: Marubozu (no shadows) in the trend direction',
            'Second candle: opposite-color Marubozu that gaps in the opposite direction',
            'Both candles have no shadows',
        ],
        interpretation: [
            'Shows a violent shift in sentiment from one session to the next',
            'The gap and Marubozu together signal overwhelming conviction',
            'Very powerful reversal pattern',
        ],
        confirmation:
            'The second Marubozu itself is the confirmation. Stops placed beyond the first Marubozu.',
    },
    CDLKICKINGBYLENGTH: {
        title: 'Kicking (by Length)',
        subtitle: 'Direction determined by the longer Marubozu',
        description:
            'A variant of Kicking where the direction (bullish or bearish) is determined by which Marubozu is longer. The longer candle wins the battle.',
        structure: [
            'Two opposite-color Marubozu candles gapping apart',
            'Direction determined by the longer-bodied candle',
        ],
        interpretation: [
            'If the white Marubozu is longer, the pattern is bullish',
            'If the black Marubozu is longer, the pattern is bearish',
        ],
        confirmation:
            'Traders enter in the direction of the longer Marubozu, with stops beyond the shorter Marubozu.',
    },
    CDLLADDERBOTTOM: {
        title: 'Ladder Bottom',
        subtitle: 'A bullish reversal at the bottom',
        description:
            'The Ladder Bottom is a five-candle bullish reversal. After four black candles with progressively lower opens, a fifth white candle gaps above the fourth, signaling the reversal.',
        structure: [
            'Four consecutive black candles, each opening lower',
            'Fifth candle: white candle that gaps above the fourth black candle',
        ],
        interpretation: [
            'The four black candles represent a cascading sell-off',
            'The gap-up white candle shows sudden buying demand',
            'Signals an abrupt end to the downtrend',
        ],
        confirmation:
            'The white candle itself is the reversal signal. Traders may wait for a higher close above the white candle.',
    },
    CDLLONGLEGGEDDOJI: {
        title: 'Long Legged Doji',
        subtitle: 'An extreme indecision signal',
        description:
            'A Long Legged Doji has a very small real body with both long upper and lower shadows. It represents a session of extreme volatility where neither side could hold gains — pure indecision.',
        structure: [
            'Open and close at or near the same price (doji body)',
            'Both upper and lower shadows are very long',
            'The total range is significantly larger than typical candles',
        ],
        interpretation: [
            'Shows a fierce battle between bulls and bears with no winner',
            'More significant after a strong trend or at key support/resistance',
            'Signals a potential trend change is coming',
        ],
        confirmation:
            'Traders wait for a decisive close above or below the doji to determine the next direction.',
    },
    CDLLONGLINE: {
        title: 'Long Line Candle',
        subtitle: 'A strong single-candle signal',
        description:
            'A Long Line Candle is simply a long-bodied candle with small or no shadows — showing that one side dominated the entire session from open to close.',
        structure: [
            'Long real body (relative to recent candles)',
            'Very small or no shadows',
            'White shows strong buying; black shows strong selling',
        ],
        interpretation: [
            'Signals strong conviction in the direction of the candle',
            'A long white line in a downtrend can signal a reversal',
            'A long black line in an uptrend can signal a top',
        ],
        confirmation:
            'Traders look for follow-through in the next candle to confirm the signal.',
    },
    CDLMARUBOZU: {
        title: 'Marubozu',
        subtitle: 'A candle with no shadows — pure conviction',
        description:
            'Marubozu means "shaved head" in Japanese. A white Marubozu opens at its low and closes at its high; a black Marubozu opens at its high and closes at its low. No shadows — absolute control by one side.',
        structure: [
            'No upper or lower shadows',
            'White Marubozu: open = low, close = high',
            'Black Marubozu: open = high, close = low',
        ],
        interpretation: [
            'White Marubozu shows total buyer control from open to close',
            'Black Marubozu shows total seller control from open to close',
            'Represents maximum conviction in the candle\'s direction',
        ],
        confirmation:
            'Traders may enter in the direction of the Marubozu, placing stops beyond its range.',
    },
    CDLMATCHINGLOW: {
        title: 'Matching Low',
        subtitle: 'A two-candle support test',
        description:
            'The Matching Low consists of two black candles with identical (or very close) lows in a downtrend — showing that support has been tested twice and held, potentially setting up a reversal.',
        structure: [
            'Two black candles in a downtrend',
            'Both candles have the same or very close low prices',
            'Can have shadows or be Marubozu-style',
        ],
        interpretation: [
            'The matching lows suggest a support level is being defended',
            'Sellers tried twice to push lower and failed both times',
            'Can precede a bullish reversal if followed by a white candle',
        ],
        confirmation:
            'Traders look for a white candle to close above the matching lows to confirm the reversal.',
    },
    CDLMATHOLD: {
        title: 'Mat Hold',
        subtitle: 'A bullish continuation pattern',
        description:
            'Mat Hold is a five-candle bullish continuation. After a long white candle, a small black candle pulls back but stays above the first candle\'s midpoint, followed by two more white candles that make new highs.',
        structure: [
            'First candle: long white candle',
            'Second candle: small black candle that stays above the first candle\'s midpoint',
            'Third candle: another small black or white candle',
            'Fourth and fifth: white candles that make new highs',
        ],
        interpretation: [
            'The pullback is shallow, showing buyers are still in control',
            'The subsequent white candles confirm the uptrend continues',
            'More common than the Rising Three Methods',
        ],
        confirmation:
            'Traders enter on the fourth or fifth candle breaking above the first candle\'s high.',
    },
    CDLMORNINGDOJISTAR: {
        title: 'Morning Doji Star',
        subtitle: 'A stronger version of the Morning Star',
        description:
            'The Morning Doji Star is identical to the Morning Star but the second candle is specifically a doji — representing even greater indecision after the gap down. This makes it a stronger bullish signal.',
        structure: [
            'First candle: long black candle in a downtrend',
            'Second candle: doji that gaps below the first',
            'Third candle: long white candle closing deep into the first candle\'s body',
        ],
        interpretation: [
            'The doji shows absolute indecision at the lows — sellers are exhausted',
            'The third white candle confirms buyers have seized control',
            'Considered stronger than a regular Morning Star because of the doji',
        ],
        confirmation:
            'The third candle serves as confirmation. A stop is placed below the doji\'s low.',
    },
    CDLONNECK: {
        title: 'On-Neck Pattern',
        subtitle: 'A bearish continuation',
        description:
            'Similar to the In-Neck, the On-Neck appears in a downtrend. A white candle gaps down and closes at the low of the prior black candle (on the "neck"), showing the bounce has no strength.',
        structure: [
            'First candle: long black candle in a downtrend',
            'Second candle: white candle that opens below and closes at the first candle\'s low',
        ],
        interpretation: [
            'Buyers attempted a bounce but could not push above the prior candle\'s low',
            'Shows that sellers are still firmly in control',
            'The downtrend is expected to continue',
        ],
        confirmation:
            'Traders look for a close below the second candle\'s low to confirm continuation.',
    },
    CDLPIERCING: {
        title: 'Piercing Pattern',
        subtitle: 'A bullish reversal at the bottom',
        description:
            'The Piercing Pattern is the bullish opposite of Dark Cloud Cover. After a long black candle, a white candle opens below the prior low but closes above the midpoint of the black candle\'s body — "piercing" through the downtrend.',
        structure: [
            'First candle: long black candle in a downtrend',
            'Second candle: opens below the first candle\'s low',
            'Second candle closes above the midpoint of the first candle\'s body',
        ],
        interpretation: [
            'The lower open creates fear, but buyers aggressively reverse the move',
            'Closing above the midpoint shows buyers have seized control',
            'The deeper the penetration, the stronger the signal',
        ],
        confirmation:
            'Traders look for follow-through above the second candle\'s high on the next bar.',
    },
    CDLRICKSHAWMAN: {
        title: 'Rickshaw Man',
        subtitle: 'A doji with a long body shadow',
        description:
            'The Rickshaw Man is a doji where the real body is in the center of the session range with both upper and lower shadows — like a Long Legged Doji but with the body centered.',
        structure: [
            'Small real body at the center of the session range',
            'Long upper and lower shadows of roughly equal length',
        ],
        interpretation: [
            'Shows equal struggle between buyers and sellers with no winner',
            'Signals indecision and potential trend change',
            'Named for its resemblance to a rickshaw driver',
        ],
        confirmation:
            'Traders wait for a close above the high or below the low for directional confirmation.',
    },
    CDLRISEFALL3METHODS: {
        title: 'Rising/Falling Three Methods',
        subtitle: 'A five-candle continuation pattern',
        description:
            'The Rising Three Methods is a bullish continuation: a long white candle followed by three small black candles that stay within the first candle\'s range, then a fifth long white candle makes a new high. Falling Three Methods is the bearish inverse.',
        structure: [
            'First candle: long candle in the trend direction',
            'Three small opposite-color candles that stay within the first candle\'s range',
            'Fifth candle: long same-color candle making a new extreme',
        ],
        interpretation: [
            'The three small candles are a brief consolidation, not a reversal',
            'The fifth candle confirms the original trend is resuming',
            'The pattern shows disciplined profit-taking, not a change of control',
        ],
        confirmation:
            'Traders enter on the close of the fifth candle, placing stops beyond the consolidation range.',
    },
    CDLSEPARATINGLINES: {
        title: 'Separating Lines',
        subtitle: 'A two-candle continuation pattern',
        description:
            'Separating Lines consist of two opposite-color candles that share the same open price. In an uptrend, a black candle opens at the same level as the prior white candle — the gap is filled and the trend continues.',
        structure: [
            'First candle: candle in the trend direction',
            'Second candle: opposite-color candle opening at the same price as the first',
        ],
        interpretation: [
            'Bullish: after a white candle, a black candle opens at the same level (filling gaps)',
            'Bearish: after a black candle, a white candle opens at the same level',
            'The second candle absorbs counter-trend pressure without reversing',
        ],
        confirmation:
            'Traders watch for a close beyond the second candle in the original trend direction.',
    },
    CDLSHOOTINGSTAR: {
        title: 'Shooting Star',
        subtitle: 'A bearish reversal at the top',
        description:
            'The Shooting Star looks like an inverted Inverted Hammer at the top of an uptrend. A small body at the lower end with a long upper shadow — buyers pushed higher but were slammed back down.',
        structure: [
            'Small real body at the lower end of the session',
            'Long upper shadow — at least 2x the body length',
            'Little or no lower shadow',
            'Appears at the top of an uptrend',
        ],
        interpretation: [
            'Buyers pushed higher but sellers aggressively rejected the move',
            'The long upper shadow shows distribution at higher levels',
            'Signals the uptrend may be exhausted',
        ],
        confirmation:
            'Traders wait for a close below the Shooting Star\'s body or a gap down to confirm.',
    },
    CDLSHORTLINE: {
        title: 'Short Line Candle',
        subtitle: 'A low-volatility signal',
        description:
            'A Short Line Candle has a very small real body relative to recent candles. It signals low volatility and indecision — often appearing before a breakout.',
        structure: [
            'Very small real body compared to recent candles',
            'Short shadows',
            'Low volatility session',
        ],
        interpretation: [
            'Shows a lack of conviction by either side',
            'Often precedes a breakout as volatility expands',
            'The direction is determined by the breakout from the Short Line range',
        ],
        confirmation:
            'Traders wait for a break above or below the Short Line candle\'s range to determine direction.',
    },
    CDLSPINNINGTOP: {
        title: 'Spinning Top',
        subtitle: 'A neutral/indecision candle',
        description:
            'A Spinning Top has a small real body with both upper and lower shadows. Neither bulls nor bears could gain meaningful ground — the session was a stalemate.',
        structure: [
            'Small real body (can be white or black)',
            'Both upper and lower shadows longer than the body',
        ],
        interpretation: [
            'Signals indecision and a balance between buyers and sellers',
            'After a strong trend, suggests momentum is fading',
            'In isolation it\'s neutral — context determines the implication',
        ],
        confirmation:
            'Wait for the next candle to close above (bullish) or below (bearish) the Spinning Top.',
    },
    CDLSTALLEDPATTERN: {
        title: 'Stalled Pattern',
        subtitle: 'A weakening bullish pattern',
        description:
            'Similar to the Advance Block, the Stalled Pattern shows three white candles where each successive candle has a smaller body and longer upper shadow — buying momentum is stalling.',
        structure: [
            'Three white candles in an uptrend',
            'Second body is roughly the same size as the first',
            'Third body is noticeably smaller with a longer upper shadow',
        ],
        interpretation: [
            'Buyers are losing steam as the uptrend progresses',
            'The longer upper shadow on the third candle is a warning',
            'May precede a pullback or reversal',
        ],
        confirmation:
            'Traders look for a close below the third candle\'s body to confirm the stall is becoming a reversal.',
    },
    CDLSTICKSANDWICH: {
        title: 'Stick Sandwich',
        subtitle: 'A bullish reversal at support',
        description:
            'The Stick Sandwich is a three-candle pattern: a black candle, followed by a white candle that closes above the first candle\'s close, then another black candle closing at the same level as the first — forming a "sandwich" that establishes support.',
        structure: [
            'First candle: black candle',
            'Second candle: white candle closing above the first',
            'Third candle: black candle closing at the same level as the first',
        ],
        interpretation: [
            'The third candle retests the level of the first and holds',
            'Establishes a potential support level',
            'The white candle in the middle shows buying interest',
        ],
        confirmation:
            'Traders look for a white candle crossing above the second candle\'s high to confirm the reversal.',
    },
    CDLTAKURI: {
        title: 'Takuri (Dragonfly Doji variant)',
        subtitle: 'A dragonfly doji with an exceptionally long lower shadow',
        description:
            'Takuri is a Japanese term for a Dragonfly Doji with a very long lower shadow — representing an even stronger rejection of lower prices. It signals that sellers were decisively overwhelmed.',
        structure: [
            'Open, high, and close at or near the same level',
            'Exceptionally long lower shadow (longer than typical Dragonfly Doji)',
            'Appears at the bottom of a downtrend',
        ],
        interpretation: [
            'An amplified version of the Dragonfly Doji',
            'The extreme lower shadow shows massive buying pressure at the lows',
            'Very bullish when confirmed',
        ],
        confirmation:
            'Traders wait for a follow-up green confirmation candle closing higher before executing trades.',
    },
    CDLTASUKIGAP: {
        title: 'Tasuki Gap',
        subtitle: 'A continuation pattern with a gap',
        description:
            'The Tasuki Gap is a three-candle continuation pattern. After a gap, the third candle moves against the gap but fails to close it — confirming the trend will continue.',
        structure: [
            'First candle: candle creating a gap in the trend direction',
            'Second candle: candle continuing in the gap direction',
            'Third candle: opposite-color candle that opens within the gap but can\'t close it',
        ],
        interpretation: [
            'The third candle attempts to fill the gap but fails',
            'The failure to close the gap confirms the trend is strong',
            'Bullish Tasuki Gap: upward gap in an uptrend; Bearish: downward gap in a downtrend',
        ],
        confirmation:
            'Confirmation comes when price moves beyond the second candle in the trend direction.',
    },
    CDLTHRUSTING: {
        title: 'Thrusting Pattern',
        subtitle: 'A weak bullish attempt',
        description:
            'The Thrusting Pattern looks like a Piercing Pattern but the white candle closes below the black candle\'s midpoint — a failed piercing that suggests the downtrend will continue.',
        structure: [
            'First candle: long black candle in a downtrend',
            'Second candle: white candle that opens lower but closes below the midpoint',
        ],
        interpretation: [
            'Buyers attempted to reverse the move but couldn\'t reach the midpoint',
            'The failure shows sellers are still dominant',
            'Different from Piercing because the close is below the 50% retracement',
        ],
        confirmation:
            'Traders look for a close below the second candle\'s low to confirm bearish continuation.',
    },
    CDLTRISTAR: {
        title: 'Tristar Pattern',
        subtitle: 'A rare three-doji reversal',
        description:
            'The Tristar consists of three consecutive doji candles. The middle doji gaps in the trend direction. Extremely rare and signals a powerful reversal when it appears.',
        structure: [
            'Three consecutive doji candles',
            'The middle doji gaps in the direction of the trend',
            'All three have very small or no real bodies',
        ],
        interpretation: [
            'Represents extreme indecision after a strong trend',
            'The gap in the middle doji marks the point of maximum trend exhaustion',
            'So rare that any appearance is considered highly significant',
        ],
        confirmation:
            'Traders look for a strong candle closing beyond the third doji to confirm direction.',
    },
    CDLUNIQUE3RIVER: {
        title: 'Unique 3 River',
        subtitle: 'A bullish reversal at the bottom',
        description:
            'The Unique 3 River is a three-candle bullish reversal. A long black candle is followed by a smaller black candle (Harami-style) that makes a lower low, then a small white candle within the second candle\'s range — forming a bottom.',
        structure: [
            'First candle: long black candle in a downtrend',
            'Second candle: smaller black candle making a lower low',
            'Third candle: small white candle within the second candle\'s range',
        ],
        interpretation: [
            'The shrinking bodies show selling pressure dissipating',
            'The white third candle shows the first sign of buying',
            'Forms a potential base for a reversal',
        ],
        confirmation:
            'Traders look for a strong white candle closing above the Unique 3 River pattern.',
    },
};

function PatternDescriptionCard({ pattern }: { pattern: string }) {
    const info = PATTERN_DESCRIPTIONS[pattern];

    if (!info) return null;

    return (
        <Card>
            <CardHeader>
                <CardTitle>{info.title}</CardTitle>
                <CardDescription>{info.subtitle}</CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                <div>
                    <p className="text-sm leading-relaxed">{info.description}</p>
                </div>

                <div>
                    <h3 className="mb-2 text-sm font-semibold">Structure and Anatomy</h3>
                    <ul className="list-disc pl-5 space-y-1 text-sm">
                        {info.structure.map((item, i) => (
                            <li key={i}>{item}</li>
                        ))}
                    </ul>
                </div>

                <div>
                    <h3 className="mb-2 text-sm font-semibold">Market Interpretation</h3>
                    <ul className="list-disc pl-5 space-y-1 text-sm">
                        {info.interpretation.map((item, i) => (
                            <li key={i}>{item}</li>
                        ))}
                    </ul>
                </div>

                <div className="rounded-md bg-orange-50 border border-orange-200 p-4 dark:bg-orange-950/20 dark:border-orange-800">
                    <p className="text-sm font-medium text-orange-700 dark:text-orange-400">
                        <strong>Confirmation:</strong> {info.confirmation}
                    </p>
                </div>
            </CardContent>
        </Card>
    );
}

export default function FiveMinuteAnalysis({ patterns, selectedPattern, results, limit, error }: Props) {
    const [isLoading, setIsLoading] = useState(false);
    const [showBullishOnly, setShowBullishOnly] = useState(true);

    const handlePatternSelect = (value: string) => {
        if (!value) return;
        setIsLoading(true);
        router.get('/ta-lib-analysis/five-minute', { pattern: value, limit }, {
            preserveState: true,
            onFinish: () => setIsLoading(false),
        });
    };

    // Auto-refresh every 5 minutes when viewing a pattern
    useEffect(() => {
        if (!selectedPattern) return;

        const interval = setInterval(() => {
            router.get('/ta-lib-analysis/five-minute', { pattern: selectedPattern, limit }, {
                preserveState: true,
                preserveScroll: true,
            });
        }, 300000);

        return () => clearInterval(interval);
    }, [selectedPattern, limit]);

    const filteredResults = results?.results.filter((r) => {
        if (!showBullishOnly) return true;
        return r.signal === 'bullish';
    }) ?? [];

    return (
        <AppLayout>
            <Head title="5 Minute Analysis" />

            <div className="space-y-6 p-6">
                <div className="flex items-center gap-3">
                    <Clock className="h-8 w-8 text-primary" />
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">5 Minute Analysis</h1>
                        <p className="text-muted-foreground">
                            Scan for candlestick patterns on 5-minute bars across the last 24 hours
                        </p>
                    </div>
                </div>

                {error && (
                    <Card className="border-destructive/50 bg-destructive/5">
                        <CardContent className="pt-4">
                            <p className="text-destructive font-medium">{error}</p>
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>Intraday Pattern Scanner</CardTitle>
                        <CardDescription>Select a candlestick pattern to scan on 5-minute bars (last 24h)</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="flex items-center gap-4">
                            <Select value={selectedPattern} onValueChange={handlePatternSelect}>
                                <SelectTrigger className="w-[320px]">
                                    <SelectValue placeholder="-- Select a Pattern --" />
                                </SelectTrigger>
                                <SelectContent>
                                    {Object.entries(patterns).map(([key, name]) => (
                                        <SelectItem key={key} value={key}>{name}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>

                            {isLoading && <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />}
                        </div>

                        {results && !isLoading && (
                            <div className="mt-3 flex items-center gap-4">
                                <p className="text-sm text-muted-foreground">
                                    Scanned {results.total_scanned} symbols —{' '}
                                    <span className="font-semibold text-foreground">{results.hits} hits</span>
                                    {' '}for <Badge variant="outline">{results.pattern_name}</Badge>
                                </p>
                                <label className="flex items-center gap-2 cursor-pointer text-sm">
                                    <input
                                        type="checkbox"
                                        checked={showBullishOnly}
                                        onChange={(e) => setShowBullishOnly(e.target.checked)}
                                        className="h-4 w-4 rounded border-gray-600 bg-gray-700 text-green-500 focus:ring-green-500"
                                    />
                                    <span className={showBullishOnly ? 'text-green-400' : 'text-muted-foreground'}>
                                        Show Bullish Only
                                    </span>
                                </label>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {selectedPattern === 'CDL3WHITESOLDIERS' && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Three Advancing White Soldiers</CardTitle>
                            <CardDescription>A powerful bullish reversal pattern</CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div>
                                <p className="text-sm leading-relaxed">
                                    The Three Advancing White Soldiers is a strong bullish candlestick pattern consisting of
                                    three consecutive long-bodied green candles that form after a downtrend or period of
                                    consolidation. Each candle opens within the previous candle&apos;s real body and closes at or
                                    near its high, demonstrating sustained buying pressure that steadily pushes prices higher.
                                    This pattern signals a decisive shift in market sentiment from bearish to bullish.{' '}
                                    <a
                                        href="https://www.investopedia.com/terms/t/three_white_soldiers.asp"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="text-blue-500 hover:underline"
                                    >
                                        [1]
                                    </a>
                                    {' '}
                                    <a
                                        href="https://www.candlestickchart.com/three-advancing-white-soldiers/"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="text-blue-500 hover:underline"
                                    >
                                        [2]
                                    </a>
                                </p>
                            </div>

                            <div>
                                <h3 className="mb-2 text-sm font-semibold">Structure and Anatomy</h3>
                                <ul className="list-disc pl-5 space-y-1 text-sm">
                                    <li>Three consecutive long-bodied bullish (green) candles</li>
                                    <li>Each candle opens within the previous candle&apos;s real body (not above it)</li>
                                    <li>Each candle closes at or near its high, ideally above the prior candle&apos;s close</li>
                                    <li>Little to no upper shadows on all three candles</li>
                                    <li>Volume should increase through the pattern to confirm conviction</li>
                                </ul>
                            </div>

                            <div>
                                <h3 className="mb-2 text-sm font-semibold">Market Interpretation</h3>
                                <ul className="list-disc pl-5 space-y-1 text-sm">
                                    <li>The first candle marks an initial shift — buyers begin absorbing selling pressure</li>
                                    <li>The second candle confirms momentum with further gains on increased volume</li>
                                    <li>The third candle completes the pattern, signaling overwhelming demand</li>
                                    <li>Ideal context: after a clear downtrend or at the end of a consolidation phase</li>
                                    <li>Stronger when accompanied by expanding volume and no overlapping wicks</li>
                                </ul>
                            </div>

                            <div className="rounded-md bg-orange-50 border border-orange-200 p-4 dark:bg-orange-950/20 dark:border-orange-800">
                                <p className="text-sm font-medium text-orange-700 dark:text-orange-400">
                                    <strong>Confirmation:</strong> Traders typically wait for a higher close or a breakout
                                    above the third candle&apos;s highs on the following bar before entering, and set stops
                                    below the first soldier&apos;s low.
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {selectedPattern === 'CDLMORNINGSTAR' && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Morning Star</CardTitle>
                            <CardDescription>A bullish three-candle reversal pattern</CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div>
                                <p className="text-sm leading-relaxed">
                                    The Morning Star is a three-candlestick bullish reversal pattern that appears at the
                                    bottom of a downtrend. It begins with a long red candle showing continued selling
                                    pressure, followed by a small-bodied indecision candle (doji or spinning top) that gaps
                                    down, and concludes with a long green candle that closes well into the first candle&apos;s
                                    body — confirming buyers have seized control.{' '}
                                    <a
                                        href="https://www.investopedia.com/terms/m/morningstar.asp"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="text-blue-500 hover:underline"
                                    >
                                        [1]
                                    </a>
                                    {' '}
                                    <a
                                        href="https://www.candlestickchart.com/morning-star/"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="text-blue-500 hover:underline"
                                    >
                                        [2]
                                    </a>
                                </p>
                            </div>

                            <div>
                                <h3 className="mb-2 text-sm font-semibold">Structure and Anatomy</h3>
                                <ul className="list-disc pl-5 space-y-1 text-sm">
                                    <li>First candle: long bearish (red) candle continuing the downtrend</li>
                                    <li>Second candle: small-bodied candle (doji/spinning top) that gaps below the first</li>
                                    <li>Third candle: long bullish (green) candle that closes above the midpoint of the first</li>
                                    <li>The second candle represents indecision and a weakening of selling momentum</li>
                                    <li>Volume typically decreases on candle 2 and surges on candle 3</li>
                                </ul>
                            </div>

                            <div>
                                <h3 className="mb-2 text-sm font-semibold">Market Interpretation</h3>
                                <ul className="list-disc pl-5 space-y-1 text-sm">
                                    <li>Candle 1 confirms the prevailing bearish trend is still in force</li>
                                    <li>Candle 2 signals that sellers are losing conviction as price stabilizes</li>
                                    <li>Candle 3 proves buyers have overwhelmed sellers and a reversal is underway</li>
                                    <li>Pattern name evokes dawn breaking — the darkness of the downtrend is lifting</li>
                                    <li>Stronger when the third candle engulfs a large portion of the first candle&apos;s body</li>
                                </ul>
                            </div>

                            <div className="rounded-md bg-orange-50 border border-orange-200 p-4 dark:bg-orange-950/20 dark:border-orange-800">
                                <p className="text-sm font-medium text-orange-700 dark:text-orange-400">
                                    <strong>Confirmation:</strong> Traders typically wait for a higher close or follow-through
                                    on the candle after the Morning Star before entering a long position. A stop is often
                                    placed below the lowest point of the three-candle formation.
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {selectedPattern === 'CDLDRAGONFLYDOJI' && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Dragonfly Doji</CardTitle>
                            <CardDescription>A unique &quot;T&quot;-shaped candlestick pattern</CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div>
                                <p className="text-sm leading-relaxed">
                                    A dragonfly doji is a unique &quot;T&quot;-shaped candlestick pattern characterized by an
                                    open, high, and closing price that are equal or nearly identical, alongside a long lower
                                    shadow. It signals a potential market reversal, reflecting intense intraday selling
                                    followed by aggressive buying.{' '}
                                    <a
                                        href="https://www.investopedia.com/ask/answers/112814/how-do-traders-interpret-dragonfly-doji-pattern.asp"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="text-blue-500 hover:underline"
                                    >
                                        [1]
                                    </a>
                                    {' '}
                                    <a
                                        href="https://www.chartguys.com/articles/candlestick-patterns-dragonfly-doji-pattern"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="text-blue-500 hover:underline"
                                    >
                                        [2]
                                    </a>
                                </p>
                            </div>

                            <div>
                                <h3 className="mb-2 text-sm font-semibold">Structure and Anatomy</h3>
                                <ul className="list-disc pl-5 space-y-1 text-sm">
                                    <li>Open, high, and close are at or near the same price level (the top of the candle)</li>
                                    <li>Little to no upper shadow (wick)</li>
                                    <li>Long lower shadow, typically 2–3 times the length of the real body</li>
                                    <li>The pattern resembles a &quot;T&quot; shape</li>
                                    <li>Most significant at the bottom of a downtrend or near support levels</li>
                                </ul>
                            </div>

                            <div>
                                <h3 className="mb-2 text-sm font-semibold">Market Interpretation</h3>
                                <ul className="list-disc pl-5 space-y-1 text-sm">
                                    <li>Sellers initially pushed price significantly lower during the session</li>
                                    <li>Buyers aggressively stepped in and drove price back to the opening level</li>
                                    <li>The long lower shadow represents the rejection of lower prices</li>
                                    <li>Indicates a shift in sentiment from bearish to neutral/bullish</li>
                                    <li>Stronger signal when accompanied by above-average volume</li>
                                </ul>
                            </div>

                            <div className="rounded-md bg-orange-50 border border-orange-200 p-4 dark:bg-orange-950/20 dark:border-orange-800">
                                <p className="text-sm font-medium text-orange-700 dark:text-orange-400">
                                    <strong>Confirmation:</strong> Traders typically wait for a follow-up green confirmation
                                    candle closing higher before executing trades.
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Generic pattern descriptions for all other patterns */}
                {selectedPattern !== 'CDL3WHITESOLDIERS' &&
                    selectedPattern !== 'CDLMORNINGSTAR' &&
                    selectedPattern !== 'CDLDRAGONFLYDOJI' && (
                    <PatternDescriptionCard pattern={selectedPattern} />
                )}

                {filteredResults.length > 0 && (
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {filteredResults.map((r) => (
                            <Card key={r.symbol} className={r.signal === 'bullish' ? 'border-l-4 border-l-green-500' : 'border-l-4 border-l-red-500'}>
                                <CardHeader className="pb-2">
                                    <div className="flex items-center justify-between">
                                        <CardTitle className="text-lg">
                                            <a
                                                href={showAsset.url(r.asset_id)}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="text-blue-600 hover:text-blue-700 hover:underline font-bold dark:text-blue-400 dark:hover:text-blue-300"
                                            >
                                                {r.symbol}
                                            </a>
                                        </CardTitle>
                                        <Badge variant={r.signal === 'bullish' ? 'default' : 'destructive'}
                                               className={r.signal === 'bullish' ? 'bg-green-600' : ''}>
                                            {r.signal === 'bullish' ? (
                                                <TrendingUp className="mr-1 h-3 w-3" />
                                            ) : (
                                                <TrendingDown className="mr-1 h-3 w-3" />
                                            )}
                                            {r.signal}
                                        </Badge>
                                    </div>
                                    <CardDescription>Last bar: {r.last_date}</CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <MiniCandlestickChart data={r.ohlc} signal={r.signal} />
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}

                {results && !isLoading && filteredResults.length === 0 && results.results.length === 0 && (
                    <Card>
                        <CardContent className="py-8 text-center">
                            <BarChart3 className="mx-auto h-12 w-12 text-muted-foreground/50" />
                            <p className="mt-4 text-lg font-medium">No patterns detected</p>
                            <p className="text-sm text-muted-foreground">
                                No {results.pattern_name} signals found across {results.total_scanned} symbols
                                on 5-minute bars for the last 24 hours
                            </p>
                        </CardContent>
                    </Card>
                )}

                {results && !isLoading && filteredResults.length === 0 && results.results.length > 0 && (
                    <Card>
                        <CardContent className="py-8 text-center">
                            <TrendingUp className="mx-auto h-12 w-12 text-muted-foreground/50" />
                            <p className="mt-4 text-lg font-medium">No bullish patterns</p>
                            <p className="text-sm text-muted-foreground">
                                {results.hits} total hits found, but none are bullish. Try toggling "Show Bullish Only" off.
                            </p>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
