const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
let listener;
class Image {
  constructor() { this.dataset = {posterFallback: 'assets/poster-placeholder.svg'}; }
  removeAttribute(name) { this.removed = name; }
}
vm.runInNewContext(fs.readFileSync('movie-recommendation-website/posters.js', 'utf8'), {
  HTMLImageElement: Image,
  document: { addEventListener(name, fn, capture) { assert.equal(name, 'error'); assert.equal(capture, true); listener = fn; } },
});
const image = new Image();
listener({target: image});
assert.equal(image.src, 'assets/poster-placeholder.svg');
assert.equal(image.dataset.posterFallback, undefined);
assert.equal(image.removed, 'srcset');
listener({target: image}); // No error loop if the fallback itself cannot load.
listener({target: {}});   // Unrelated asset errors are ignored.
console.log('Poster browser error-handler tests passed.');
