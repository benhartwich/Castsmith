from quill import DEFS, mark, stars
W,H=1544,500
banner=f'''<svg xmlns="http://www.w3.org/2000/svg" width="{W}" height="{H}" viewBox="0 0 {W} {H}">
{DEFS}
<rect width="{W}" height="{H}" fill="url(#bg)"/>
<ellipse cx="1230" cy="230" rx="430" ry="330" fill="url(#glow)"/>
{stars(W,H,170,5,avoid=lambda x,y: (60<x<900 and 110<y<420) or ((x-1240)**2/330**2+(y-250)**2/260**2<0.7), rmax=1.8)}
<g transform="translate(1030,18) scale(1.78)">
{mark()}
</g>
<text x="96" y="238" font-family="Noto Sans" font-weight="700" font-size="128" fill="#ffffff" letter-spacing="-1">Sonoquill</text>
<text x="100" y="304" font-family="Noto Sans" font-weight="500" font-size="40" fill="#ffd36b">Podcast episodes from your texts —</text>
<text x="100" y="354" font-family="Noto Sans" font-weight="500" font-size="40" fill="#ffd36b">spoken in your own voice.</text>
<text x="100" y="418" font-family="Noto Sans" font-weight="400" font-size="26" fill="#c9cff5">Script · number check · voice clone · assembly · Podlove draft</text>
<text x="100" y="456" font-family="Noto Sans" font-weight="400" font-size="26" fill="#c9cff5">Nothing goes online without your two approvals.</text>
</svg>'''
open('banner.svg','w').write(banner)
