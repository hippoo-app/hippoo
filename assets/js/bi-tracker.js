(function () {
    'use strict';

    let sid = document.cookie.match(/hpbi=([^;]+)/)?.[1];

    if (!sid) {
        sid = Math.random().toString(36).substring(2, 18);
        document.cookie = `hpbi=${sid};max-age=1800;path=/;SameSite=Lax`;
    }

	navigator.sendBeacon(hippooBI.rest_url, JSON.stringify({
        url: location.pathname + location.search,
        ref: document.referrer || '',
        sid: sid,
        pid: hippooBI.product_id || null
    }));
})();