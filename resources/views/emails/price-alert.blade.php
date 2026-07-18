<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 8px 8px 0 0; }
        .content { background: #f9f9f9; padding: 20px; border: 1px solid #ddd; }
        .alert-box { background: white; padding: 15px; border-left: 4px solid #667eea; margin: 15px 0; border-radius: 4px; }
        .alert-box dt { font-weight: bold; color: #667eea; margin-top: 10px; }
        .alert-box dd { margin-left: 0; margin-bottom: 5px; }
        .button { display: inline-block; background: #667eea; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; margin: 20px 0; }
        .footer { background: #f5f5f5; padding: 15px; text-align: center; font-size: 12px; color: #666; border-radius: 0 0 8px 8px; }
        .emoji { font-size: 24px; margin-right: 10px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>
                <span class="emoji">{{ $direction === 'up' ? '📈' : '📉' }}</span>
                Price Alert Triggered!
            </h1>
        </div>
        
        <div class="content">
            <p>Hello,</p>
            
            <p>Your price alert for <strong>{{ $symbol }}</strong> ({{ $commonName }}) has been triggered.</p>
            
            <div class="alert-box">
                <dl>
                    <dt>Symbol</dt>
                    <dd>{{ $symbol }}</dd>
                    
                    <dt>Current Price</dt>
                    <dd>${{ number_format($currentPrice, 2) }}</dd>
                    
                    <dt>Alert Type</dt>
                    <dd>{{ $direction === 'up' ? 'Price Above' : 'Price Below' }}</dd>
                    
                    <dt>Trigger Price</dt>
                    <dd>${{ number_format($triggerPrice, 2) }}</dd>
                    
                    <dt>Threshold</dt>
                    <dd>{{ number_format($percentage, 1) }}%</dd>
                    
                    <dt>Base Price</dt>
                    <dd>${{ number_format($basePrive, 2) }}</dd>
                </dl>
            </div>
            
            @if ($direction === 'up')
                <p>The price of <strong>{{ $symbol }}</strong> has reached or exceeded your alert level of <strong>${{ number_format($triggerPrice, 2) }}</strong>.</p>
                <p>Current price is now at <strong>${{ number_format($currentPrice, 2) }}</strong> - a <strong>{{ number_format((($currentPrice - $basePrive) / $basePrive) * 100, 1) }}%</strong> change from your base price.</p>
            @else
                <p>The price of <strong>{{ $symbol }}</strong> has dropped to or below your alert level of <strong>${{ number_format($triggerPrice, 2) }}</strong>.</p>
                <p>Current price is now at <strong>${{ number_format($currentPrice, 2) }}</strong> - a <strong>{{ number_format((($basePrive - $currentPrice) / $basePrive) * 100, 1) }}%</strong> change from your base price.</p>
            @endif
            
            <a href="{{ route('watches.index') }}" class="button">View Your Watchlist</a>
            
            <hr style="margin: 20px 0; border: none; border-top: 1px solid #ddd;">
            
            <p style="font-size: 12px; color: #666;">
                Don't want these alerts? You can manage your price alerts in your <a href="{{ route('notifications.settings') }}">notification settings</a>.
            </p>
        </div>
        
        <div class="footer">
            <p>{{ config('app.name') }} Team</p>
            <p>This is an automated message. Please do not reply to this email.</p>
        </div>
    </div>
</body>
</html>
