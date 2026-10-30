'use strict';
// Capture errors from CDN redirects too. No inline script or API credential.
document.addEventListener('error', function (event) {
  const image = event.target;
  if (!(image instanceof HTMLImageElement) || !image.dataset.posterFallback) return;
  const fallback = image.dataset.posterFallback;
  delete image.dataset.posterFallback;
  image.removeAttribute('srcset');
  image.src = fallback;
  image.alt = 'Afiş şu anda kullanılamıyor';
}, true);
