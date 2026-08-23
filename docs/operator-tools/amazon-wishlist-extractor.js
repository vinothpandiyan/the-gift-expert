/**
 * Amazon India wishlist extractor — Phase 20A curated intake
 *
 * Usage:
 * 1. Open an Amazon.in wishlist or product list page while signed in.
 * 2. Open DevTools → Console.
 * 3. Paste this entire file and press Enter.
 * 4. Copy the printed JSON into Filament → Catalog → Curated Product Intake.
 *
 * The script only reads the current page DOM. It does not send network requests,
 * attach affiliate tags, classify products, or store credentials.
 */
(function () {
    'use strict';

    const MERCHANT = 'amazon-in';
    const VERSION = 1;

    const ITEM_SELECTORS = [
        '[data-itemid][data-asin]',
        '[data-asin]',
        '.a-section.gift-list-item',
        '.g-item-sortable',
        '[id^="itemWrapper_"]',
    ];

    const TITLE_SELECTORS = [
        '.a-truncate-full',
        'h2 a span',
        'a.a-link-normal[href*="/dp/"] span',
        '.a-link-normal .a-truncate-cut',
    ];

    const PRICE_SELECTORS = [
        '.a-price .a-offscreen',
        '.a-price-whole',
        '.a-color-price',
    ];

    const IMAGE_SELECTORS = [
        'img[src*="media-amazon"]',
        'img.s-product-image',
        '.a-dynamic-image',
    ];

    function text(el) {
        return (el && el.textContent ? el.textContent : '').replace(/\s+/g, ' ').trim();
    }

    function findFirst(root, selectors) {
        for (const selector of selectors) {
            const node = root.querySelector(selector);
            if (node) {
                return node;
            }
        }

        return null;
    }

    function extractAsin(node) {
        const direct = node.getAttribute('data-asin') || node.getAttribute('data-itemid');
        if (direct && /^[A-Z0-9]{10}$/i.test(direct)) {
            return direct.toUpperCase();
        }

        const link = node.querySelector('a[href*="/dp/"], a[href*="/gp/product/"]');
        if (!link) {
            return null;
        }

        const href = link.getAttribute('href') || '';
        const match = href.match(/\/(?:dp|gp\/product)\/([A-Z0-9]{10})/i);

        return match ? match[1].toUpperCase() : null;
    }

    function canonicalProductUrl(asin) {
        return `https://www.amazon.in/dp/${asin}`;
    }

    function extractPrice(node) {
        for (const selector of PRICE_SELECTORS) {
            const priceNode = node.querySelector(selector);
            const value = text(priceNode);
            if (value) {
                const numeric = value.replace(/[^0-9.,]/g, '').replace(/,/g, '');
                if (numeric) {
                    return numeric;
                }
            }
        }

        return null;
    }

    function extractImage(node) {
        for (const selector of IMAGE_SELECTORS) {
            const img = node.querySelector(selector);
            const src = img ? img.getAttribute('src') : null;
            if (src && src.startsWith('http')) {
                return src;
            }
        }

        return null;
    }

    function extractAvailability(node) {
        const body = text(node).toLowerCase();
        if (body.includes('currently unavailable') || body.includes('out of stock')) {
            return 'out_of_stock';
        }
        if (body.includes('unavailable')) {
            return 'unavailable';
        }
        if (body.includes('in stock') || body.includes('add to cart') || body.includes('add to basket')) {
            return 'in_stock';
        }

        return 'unknown';
    }

    function collectNodes() {
        const seen = new Set();
        const nodes = [];

        for (const selector of ITEM_SELECTORS) {
            document.querySelectorAll(selector).forEach((node) => {
                if (seen.has(node)) {
                    return;
                }
                seen.add(node);
                nodes.push(node);
            });
        }

        return nodes;
    }

    const items = [];
    const seenAsins = new Set();

    collectNodes().forEach((node) => {
        const asin = extractAsin(node);
        if (!asin || seenAsins.has(asin)) {
            return;
        }

        seenAsins.add(asin);

        const titleNode = findFirst(node, TITLE_SELECTORS);
        const title = text(titleNode);
        if (!title) {
            return;
        }

        const item = {
            external_product_id: asin,
            source_url: canonicalProductUrl(asin),
            title,
            availability: extractAvailability(node),
        };

        const price = extractPrice(node);
        if (price) {
            item.price_amount = price;
            item.price_currency = 'INR';
        }

        const image = extractImage(node);
        if (image) {
            item.source_image_url = image;
        }

        items.push(item);
    });

    const payload = {
        version: VERSION,
        merchant: MERCHANT,
        captured_at: new Date().toISOString(),
        context: {
            curation_group: 'unspecified',
        },
        items,
    };

    const json = JSON.stringify(payload, null, 2);
    console.log(json);

    if (typeof copy === 'function') {
        try {
            copy(json);
            console.log(`Copied ${items.length} item(s) to clipboard.`);
        } catch (error) {
            console.warn('Clipboard copy failed. Copy the JSON from the console output.');
        }
    }

    return payload;
})();
