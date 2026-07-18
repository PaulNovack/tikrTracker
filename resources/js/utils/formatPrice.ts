/**
 * Format price with 4 decimals for stocks < $10, 2 decimals for >= $10
 */
export function formatPrice(price: number | string): string {
    const numPrice = typeof price === 'string' ? parseFloat(price) : price;
    
    if (isNaN(numPrice)) {
        return '$0.00';
    }
    
    const decimals = numPrice < 10 ? 4 : 2;
    return `$${numPrice.toFixed(decimals)}`;
}

/**
 * Format price without dollar sign
 */
export function formatPriceValue(price: number | string): string {
    const numPrice = typeof price === 'string' ? parseFloat(price) : price;
    
    if (isNaN(numPrice)) {
        return '0.00';
    }
    
    const decimals = numPrice < 10 ? 4 : 2;
    return numPrice.toFixed(decimals);
}
