# Brand and asset notes

The custom `mark.svg` combines an architectural arch, a botanical sprig, and a quiet horizon. Phase 2 strengthens the strokes and places the linen mark on a forest badge with a linen edge. The SVG is enlarged in the header (58px desktop, 46px mobile), and reused on the dark footer. The wordmark spacing and size also increase. It remains crisp without raster scaling.

The five JPEGs are bundled locally from Unsplash. They are **illustrative mood photography**, not verified photos of Bella Vista or an inventory of room amenities. The website discloses this. Replace them with the actual property's photography before a public business launch. In particular, the couples photo conveys atmosphere and does not document the real two-person suite.

| Local asset | Source |
|---|---|
| hero.jpg | [Spa room by ikhbale](https://unsplash.com/photos/lyicBM-7zFA) |
| single.jpg | [Treatment room by visualsofdana](https://unsplash.com/photos/IfsRTQu6Djk) |
| couples.jpg | [Treatment room by visualsofdana](https://unsplash.com/photos/GCIgPlc0SLU) |
| lounge.jpg | [Interior photograph](https://images.unsplash.com/photo-1600210492486-724fe5c67fb0) |
| detail.jpg | [Massage photograph](https://images.unsplash.com/photo-1600334129128-685c5582fd35) |

Exact source URLs are also recorded in `public/assets/images/sources.json`. See the [Unsplash license](https://unsplash.com/license). No remote image requests are made by the running website.

Typography uses locally hosted **Cormorant Garamond** (regular/italic variable fonts) and **DM Sans** under the SIL Open Font License. Both license files are bundled alongside the WOFF2 files. System serif/sans fallbacks cover glyphs outside the bundled Latin subsets.

`docs/fetch_assets.py` is an optional maintenance script to retrieve these assets again from their public sources. Python and network access are not needed to run the website.
