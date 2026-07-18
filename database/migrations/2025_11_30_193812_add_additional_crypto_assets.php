<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add 28 additional cryptocurrency assets
        $cryptoAssets = [
            // Top Market Cap Cryptos
            ['symbol' => 'XRP', 'common_name' => 'Ripple', 'description' => 'Digital payment network and cryptocurrency designed for fast, low-cost international transfers. Used by financial institutions worldwide for cross-border payments and remittances.'],
            ['symbol' => 'USDC', 'common_name' => 'USD Coin', 'description' => 'Fully-backed US dollar stablecoin issued by Circle. Maintained at 1:1 parity with USD and backed by cash and short-term government securities, widely used in DeFi.'],
            ['symbol' => 'USDT', 'common_name' => 'Tether', 'description' => 'The world\'s largest stablecoin by market cap, pegged to the US dollar. Widely used for trading, storing value, and as a medium of exchange in crypto markets.'],
            ['symbol' => 'AVAX', 'common_name' => 'Avalanche', 'description' => 'High-performance blockchain platform for decentralized applications and custom blockchain networks. Known for fast transaction speeds, low fees, and Ethereum compatibility.'],
            ['symbol' => 'DOT', 'common_name' => 'Polkadot', 'description' => 'Multi-chain blockchain protocol that enables different blockchains to transfer messages and value in a trust-free fashion. Focuses on interoperability and scalability.'],
            ['symbol' => 'MATIC', 'common_name' => 'Polygon', 'description' => 'Ethereum scaling solution that provides faster and cheaper transactions through sidechains and layer-2 scaling. Major infrastructure for DeFi and NFT applications.'],
            ['symbol' => 'LTC', 'common_name' => 'Litecoin', 'description' => 'Peer-to-peer cryptocurrency created as "digital silver" to Bitcoin\'s "digital gold". Features faster block generation times and lower transaction fees than Bitcoin.'],
            ['symbol' => 'LINK', 'common_name' => 'Chainlink', 'description' => 'Decentralized oracle network that provides real-world data to smart contracts. Critical infrastructure connecting blockchain applications to external data sources and APIs.'],
            ['symbol' => 'UNI', 'common_name' => 'Uniswap', 'description' => 'Governance token for the Uniswap protocol, the leading decentralized exchange on Ethereum. Enables automated market making and decentralized token swapping.'],
            ['symbol' => 'ATOM', 'common_name' => 'Cosmos', 'description' => 'Blockchain network focused on interoperability between independent blockchains. Provides tools and protocols for building and connecting sovereign blockchain applications.'],

            // Layer 1 Blockchains
            ['symbol' => 'ALGO', 'common_name' => 'Algorand', 'description' => 'Pure proof-of-stake blockchain platform designed for speed, security, and decentralization. Features instant finality, low fees, and carbon-negative network operations.'],
            ['symbol' => 'FTM', 'common_name' => 'Fantom', 'description' => 'High-performance, scalable blockchain platform for DeFi, crypto dApps, and enterprise applications. Known for fast transaction speeds and low costs.'],
            ['symbol' => 'NEAR', 'common_name' => 'Near Protocol', 'description' => 'Developer-friendly blockchain platform designed for usability and scalability. Features sharding technology and focuses on mainstream adoption of decentralized applications.'],
            ['symbol' => 'ICP', 'common_name' => 'Internet Computer', 'description' => 'Blockchain network that aims to extend the internet with decentralized computing capabilities. Allows smart contracts to serve web content directly to users.'],
            ['symbol' => 'FLOW', 'common_name' => 'Flow', 'description' => 'Blockchain designed for NFTs, gaming, and consumer applications. Created by Dapper Labs, the team behind CryptoKitties and NBA Top Shot.'],

            // DeFi Tokens
            ['symbol' => 'AAVE', 'common_name' => 'Aave', 'description' => 'Leading decentralized lending protocol that allows users to lend and borrow cryptocurrencies. Features include flash loans, rate switching, and collateralized borrowing.'],
            ['symbol' => 'CRV', 'common_name' => 'Curve DAO Token', 'description' => 'Governance token for Curve Finance, a decentralized exchange optimized for stablecoin trading. Provides low slippage swaps between similar assets.'],
            ['symbol' => 'COMP', 'common_name' => 'Compound', 'description' => 'Governance token for the Compound protocol, an algorithmic money market for lending and borrowing cryptocurrencies. Pioneered yield farming in DeFi.'],
            ['symbol' => 'MKR', 'common_name' => 'Maker', 'description' => 'Governance token for MakerDAO, the protocol behind the DAI stablecoin. Holders vote on system parameters and risk management for the decentralized stable currency.'],
            ['symbol' => 'SUSHI', 'common_name' => 'SushiSwap', 'description' => 'Governance token for SushiSwap, a decentralized exchange and DeFi platform. Evolved from Uniswap fork to include additional features like lending and yield farming.'],

            // Gaming/NFT
            ['symbol' => 'MANA', 'common_name' => 'Decentraland', 'description' => 'Virtual reality platform token where users can create, experience, and monetize content and applications in a 3D virtual world built on Ethereum blockchain.'],
            ['symbol' => 'SAND', 'common_name' => 'The Sandbox', 'description' => 'Gaming metaverse token that allows players to create, own, and monetize virtual worlds and assets. Features user-generated content and play-to-earn mechanics.'],
            ['symbol' => 'AXS', 'common_name' => 'Axie Infinity', 'description' => 'Governance token for Axie Infinity, a blockchain-based trading and battling game. Players collect, breed, and battle fantasy creatures called Axies.'],
            ['symbol' => 'ENJ', 'common_name' => 'Enjin Coin', 'description' => 'Cryptocurrency for gaming and NFT applications. Allows developers to create and manage virtual goods, with focus on gaming ecosystems and digital collectibles.'],

            // Infrastructure/Utility
            ['symbol' => 'VET', 'common_name' => 'VeChain', 'description' => 'Blockchain platform focused on supply chain management and business processes. Provides transparency and traceability for products from manufacturing to consumer.'],
            ['symbol' => 'THETA', 'common_name' => 'Theta Network', 'description' => 'Decentralized video delivery network powered by blockchain technology. Improves video streaming quality while reducing costs for content delivery.'],
            ['symbol' => 'FIL', 'common_name' => 'Filecoin', 'description' => 'Decentralized storage network that allows users to rent out unused hard drive space or pay to store files. Creates a marketplace for data storage services.'],
            ['symbol' => 'GRT', 'common_name' => 'The Graph', 'description' => 'Indexing protocol for querying blockchain data. Provides infrastructure for decentralized applications to efficiently access and organize blockchain information.'],
        ];

        foreach ($cryptoAssets as $asset) {
            DB::table('asset_info')->insert([
                'symbol' => $asset['symbol'],
                'common_name' => $asset['common_name'],
                'asset_type' => 'crypto',
                'description' => $asset['description'],
                'sector' => null, // Crypto doesn't have traditional sectors
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove the added crypto assets
        $symbols = [
            'XRP', 'USDC', 'USDT', 'AVAX', 'DOT', 'MATIC', 'LTC', 'LINK', 'UNI', 'ATOM',
            'ALGO', 'FTM', 'NEAR', 'ICP', 'FLOW', 'AAVE', 'CRV', 'COMP', 'MKR', 'SUSHI',
            'MANA', 'SAND', 'AXS', 'ENJ', 'VET', 'THETA', 'FIL', 'GRT',
        ];

        DB::table('asset_info')
            ->where('asset_type', 'crypto')
            ->whereIn('symbol', $symbols)
            ->delete();
    }
};
