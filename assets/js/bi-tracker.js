(function () {
    'use strict';

    const TWO_YEARS_IN_SECONDS = 2 * 365 * 24 * 60 * 60;

    let sid = document.cookie.match(/hpbi=([^;]+)/)?.[1];

    if (!sid) {
        sid = Math.random().toString(36).substring(2, 18);
        document.cookie = `hpbi=${sid};max-age=${TWO_YEARS_IN_SECONDS};path=/;SameSite=Lax`;
    }

	navigator.sendBeacon(hippooBI.rest_url, JSON.stringify({
        url: location.pathname + location.search,
        ref: document.referrer || '',
        sid: sid,
        pid: hippooBI.product_id || null
    }));
})();