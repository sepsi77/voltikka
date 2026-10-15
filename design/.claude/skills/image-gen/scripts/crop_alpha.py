# Usage: uv run --with pillow python -I crop_alpha.py IN OUT [margin]
# Crops a transparent image to the bounding box of its visible pixels.
import sys
from PIL import Image
im=Image.open(sys.argv[1]).convert('RGBA'); m=int(sys.argv[3]) if len(sys.argv)>3 else 12
a=im.split()[3].point(lambda v:255 if v>16 else 0); b=a.getbbox()
if b: im=im.crop((max(b[0]-m,0),max(b[1]-m,0),min(b[2]+m,im.width),min(b[3]+m,im.height)))
im.save(sys.argv[2]); print(sys.argv[2], im.size)
