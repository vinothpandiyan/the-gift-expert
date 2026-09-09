(async () => {
    const CONFIG = {
        scrollDelayMs: 1200,
        maxNoGrowthRounds: 5,
        maxScrollRounds: 100,
    };

    const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

    const cleanText = (value) =>
        (value || '')
            .replace(/\s+/g, ' ')
            .trim();

    const absoluteUrl = (url) => {
        if (!url) return null;

        try {
            return new URL(url, window.location.origin).href;
        } catch {
            return null;
        }
    };

    const extractAsinFromUrl = (url) => {
        if (!url) return null;

        const match = url.match(
            /\/(?:dp|gp\/product|gp\/aw\/d)\/([A-Z0-9]{10})(?:[/?]|$)/i
        );

        return match ? match[1].toUpperCase() : null;
    };

    const canonicalProductUrl = (asin) =>
        asin ? `https://www.amazon.in/dp/${asin}` : null;

    const extractPrice = (container) => {
        const priceElement = container.querySelector(
            '.a-price .a-offscreen'
        );
    
        if (priceElement) {
            const text = cleanText(priceElement.textContent);
    
            const match = text
                .replace(/,/g, '')
                .match(/(?:₹|INR\s*)([\d]+(?:\.\d{1,2})?)/i);
    
            if (match) {
                return Number(match[1]).toFixed(2);
            }
        }
    
        const priceContainer = container.querySelector('.a-price');
    
        if (priceContainer) {
            const whole = cleanText(
                priceContainer.querySelector('.a-price-whole')?.textContent
            ).replace(/[^\d]/g, '');
    
            const fraction = cleanText(
                priceContainer.querySelector('.a-price-fraction')?.textContent
            ).replace(/[^\d]/g, '');
    
            if (whole) {
                return `${whole}.${fraction || '00'}`;
            }
        }
    
        return null;
    };

    const extractAvailability = (container, priceAmount) => {
        const text = cleanText(container.innerText).toLowerCase();
    
        // Explicit Amazon unavailable states always win.
        if (
            text.includes('currently unavailable') ||
            text.includes('temporarily out of stock') ||
            text.includes('out of stock') ||
            text.includes('currently not available')
        ) {
            return 'out_of_stock';
        }
    
        // On Amazon wishlist pages, a usable displayed price is our
        // strongest signal that the product is currently commercially usable.
        //
        // "See all buying options" must NOT make the product unknown.
        if (
            priceAmount !== null &&
            priceAmount !== undefined &&
            String(priceAmount).trim() !== ''
        ) {
            return 'in_stock';
        }
    
        // For this curated affiliate workflow, an Amazon wishlist item
        // without a usable price is treated as currently unavailable.
        return 'out_of_stock';
    };

    const findProductLink = (container) => {
        const links = [...container.querySelectorAll('a[href]')];

        for (const link of links) {
            const href = absoluteUrl(link.getAttribute('href'));

            if (extractAsinFromUrl(href)) {
                return {
                    element: link,
                    href,
                };
            }
        }

        return null;
    };

    const extractTitle = (container, productLink) => {
        const selectors = [
            '[id^="itemName_"]',
            '[id*="itemName"]',
            '.a-size-base-plus',
            '.a-size-medium',
            'h2 a',
            'h3 a',
            'h2',
            'h3',
        ];

        for (const selector of selectors) {
            const element = container.querySelector(selector);
            const title = cleanText(element?.textContent);

            if (
                title &&
                title.length >= 3 &&
                !/^add to/i.test(title) &&
                !/^delete/i.test(title)
            ) {
                return title;
            }
        }

        const linkTitle = cleanText(
            productLink?.element?.getAttribute('title')
        );

        if (linkTitle) {
            return linkTitle;
        }

        const ariaLabel = cleanText(
            productLink?.element?.getAttribute('aria-label')
        );

        if (ariaLabel) {
            return ariaLabel;
        }

        const imageAlt = cleanText(
            container.querySelector('img')?.getAttribute('alt')
        );

        if (imageAlt && imageAlt.length >= 3) {
            return imageAlt;
        }

        const linkText = cleanText(productLink?.element?.textContent);

        if (linkText && linkText.length >= 3) {
            return linkText;
        }

        return null;
    };

    const extractImageUrl = (container) => {
        const image =
            container.querySelector('img.a-dynamic-image') ||
            container.querySelector('img[src]');

        if (!image) return null;

        const dynamic = image.getAttribute('data-a-dynamic-image');

        if (dynamic) {
            try {
                const sizes = JSON.parse(dynamic);
                let bestUrl = null;
                let bestArea = 0;

                for (const [url, dims] of Object.entries(sizes)) {
                    const area = Number(dims?.[0] || 0) * Number(dims?.[1] || 0);

                    if (area > bestArea) {
                        bestUrl = url;
                        bestArea = area;
                    }
                }

                if (bestUrl) {
                    return normalizeAmazonImageUrl(absoluteUrl(bestUrl));
                }
            } catch {
                // Fall through to simpler attributes.
            }
        }

        const raw =
            image.currentSrc ||
            image.getAttribute('data-old-hires') ||
            image.src ||
            null;

        return normalizeAmazonImageUrl(absoluteUrl(raw));
    };

    const normalizeAmazonImageUrl = (url) => {
        if (!url) return null;

        try {
            const parsed = new URL(url);
            const host = parsed.hostname.toLowerCase();
            const isAmazonImage =
                host === 'm.media-amazon.com' ||
                host.endsWith('.media-amazon.com') ||
                host.endsWith('.ssl-images-amazon.com') ||
                host.endsWith('.images-amazon.com') ||
                host === 'images-amazon.com';

            if (!isAmazonImage) {
                return parsed.href;
            }

            const match = parsed.pathname.match(
                /\/images\/I\/([^/.]+)(?:\._[A-Za-z0-9,_]+)?(\.[A-Za-z0-9]+)$/i
            );

            if (!match) {
                return parsed.href;
            }

            const id = decodeURIComponent(match[1]);
            const ext = match[2].toLowerCase();

            return `https://m.media-amazon.com/images/I/${id}._SS1200_${ext}`;
        } catch {
            return url;
        }
    };

    const discoverProductContainers = () => {
        const candidateSelectors = [
            'li[data-itemid]',
            'div[data-itemid]',
            '[id^="item_"]',
            '.g-item-sortable',
        ];

        const containers = [];

        for (const selector of candidateSelectors) {
            document.querySelectorAll(selector).forEach((element) => {
                if (!containers.includes(element)) {
                    containers.push(element);
                }
            });
        }

        return containers;
    };

    const products = new Map();

    const scanCurrentDom = () => {
        const containers = discoverProductContainers();

        for (const container of containers) {
            const productLink = findProductLink(container);

            if (!productLink) continue;

            const asin = extractAsinFromUrl(productLink.href);

            if (!asin) continue;

            const title = extractTitle(container, productLink);
            const price = extractPrice(container);
            const imageUrl = extractImageUrl(container);
            const availability = extractAvailability(container, price);

            const existing = products.get(asin);

            products.set(asin, {
                external_product_id: asin,
                source_url: canonicalProductUrl(asin),
                title: title || existing?.title || null,
                price_amount: price || existing?.price_amount || null,
                price_currency: 'INR',
                source_image_url:
                    imageUrl || existing?.source_image_url || null,
                availability:
                    availability !== 'unknown'
                        ? availability
                        : existing?.availability || 'unknown',
            });
        }
    };

    console.log('Amazon Wishlist Extractor v2');
    console.log('Scanning wishlist and auto-scrolling…');

    let previousCount = 0;
    let noGrowthRounds = 0;

    for (let round = 1; round <= CONFIG.maxScrollRounds; round++) {
        scanCurrentDom();

        const currentCount = products.size;

        console.log(
            `Round ${round}: ${currentCount} unique ASIN(s) collected`
        );

        if (currentCount === previousCount) {
            noGrowthRounds++;
        } else {
            noGrowthRounds = 0;
        }

        previousCount = currentCount;

        if (noGrowthRounds >= CONFIG.maxNoGrowthRounds) {
            break;
        }

        window.scrollTo({
            top: document.body.scrollHeight,
            behavior: 'smooth',
        });

        await sleep(CONFIG.scrollDelayMs);

        // Some Amazon pages load more when slightly moving around the bottom.
        window.scrollBy(0, -300);
        await sleep(250);
        window.scrollBy(0, 300);
        await sleep(500);
    }

    // Final scan after lazy-loaded content settles.
    await sleep(1000);
    scanCurrentDom();

    const listIdMatch = window.location.href.match(
        /\/hz\/wishlist\/ls\/([^/?#]+)/i
    );

    const sourceListId = listIdMatch ? listIdMatch[1] : null;

    const wishlistNameSelectors = [
        '#profile-list-name',
        '#list-name',
        '[data-action="edit-list-name"]',
        'h1',
    ];

    let sourceListName = null;

    for (const selector of wishlistNameSelectors) {
        const element = document.querySelector(selector);
        const value = cleanText(element?.textContent);

        if (value) {
            sourceListName = value;
            break;
        }
    }

    if (!sourceListName) {
        sourceListName = document.title
            .replace(/\s*-\s*Amazon.*$/i, '')
            .trim();
    }

    const payload = {
        version: 2,
        merchant: 'amazon-in',
        captured_at: new Date().toISOString(),
        context: {
            source_list_name: sourceListName || null,
            source_list_id: sourceListId,
            source_list_url: sourceListId
                ? `https://www.amazon.in/hz/wishlist/ls/${sourceListId}`
                : window.location.href.split('?')[0],
        },
        items: [...products.values()],
    };

    const missingTitles = payload.items.filter((item) => !item.title);

    console.table(
        payload.items.map((item) => ({
            asin: item.external_product_id,
            title: item.title,
            price: item.price_amount,
            availability: item.availability,
        }))
    );

    console.log('----------------------------------------');
    console.log(`Wishlist: ${payload.context.source_list_name}`);
    console.log(`List ID: ${payload.context.source_list_id}`);
    console.log(`Products extracted: ${payload.items.length}`);
    console.log(`Products missing title: ${missingTitles.length}`);

    if (missingTitles.length) {
        console.warn(
            'ASINs missing titles:',
            missingTitles.map((item) => item.external_product_id)
        );
    }

    const suspiciousPrices = payload.items.filter((item) => {
        if (!item.price_amount) return false;
    
        const price = Number(item.price_amount);
    
        return !Number.isFinite(price) || price <= 10;
    });
    
    if (suspiciousPrices.length) {
        console.warn(
            '⚠️ Suspicious prices detected:',
            suspiciousPrices.map((item) => ({
                asin: item.external_product_id,
                title: item.title,
                price: item.price_amount,
            }))
        );
    }

    const json = JSON.stringify(payload, null, 2);

    console.log(json);

    try {
        await navigator.clipboard.writeText(json);
        console.log('✅ JSON copied to clipboard.');
    } catch {
        console.warn(
            'Clipboard access was blocked. Copy the JSON printed above manually.'
        );
    }

    // Return payload so Chrome console also exposes the object.
    return payload;
})();