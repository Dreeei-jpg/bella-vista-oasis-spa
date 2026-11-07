"""Re-download the bundled demo photography and open-source fonts. Network required."""
import concurrent.futures
import json
import pathlib
import re
import urllib.request

ROOT = pathlib.Path(__file__).resolve().parents[1] / 'public' / 'assets'
PHOTOS = {
    'hero': 'photo-1737352777897-e22953991a32',
    'single': 'photo-1611920630418-f587fdc3bf94',
    'couples': 'photo-1611920629515-3f76f8c36b37',
    'lounge': 'photo-1600210492486-724fe5c67fb0',
    'detail': 'photo-1600334129128-685c5582fd35',
}

def photo(item):
    name, photo_id = item
    source = f'https://images.unsplash.com/{photo_id}?auto=format&fit=crop&w=1600&q=85'
    data = urllib.request.urlopen(source, timeout=60).read()
    (ROOT / 'images' / f'{name}.jpg').write_bytes(data)
    return {'name': name, 'source': source, 'bytes': len(data)}

if __name__ == '__main__':
    (ROOT / 'fonts').mkdir(exist_ok=True)
    with concurrent.futures.ThreadPoolExecutor(max_workers=5) as pool:
        results = list(pool.map(photo, PHOTOS.items()))
    (ROOT / 'images' / 'sources.json').write_text(json.dumps(results, indent=2))
    print(json.dumps(results, indent=2))
    request = urllib.request.Request(
        'https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300..700;1,300..700&family=DM+Sans:wght@100..900&display=swap',
        headers={'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'},
    )
    css = urllib.request.urlopen(request, timeout=60).read().decode()
    for block in css.split('/* latin */')[1:]:
        block = block.split('}')[0]
        name = 'cormorant-garamond' if 'Cormorant' in block else 'dm-sans'
        if 'font-style: italic' in block:
            name += '-italic'
        source = re.search(r'url\(([^)]+)\)', block).group(1)
        destination = ROOT / 'fonts' / f'{name}-latin.woff2'
        destination.write_bytes(urllib.request.urlopen(source, timeout=60).read())
        print(destination.name, destination.stat().st_size)
    for folder, target in [('cormorantgaramond', 'Cormorant-Garamond-OFL.txt'), ('dmsans', 'DM-Sans-OFL.txt')]:
        source = f'https://raw.githubusercontent.com/google/fonts/main/ofl/{folder}/OFL.txt'
        (ROOT / 'fonts' / target).write_bytes(urllib.request.urlopen(source, timeout=60).read())
