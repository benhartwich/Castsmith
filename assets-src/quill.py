import math, random

def bez(p0,p1,p2,p3,t):
    u=1-t
    return tuple(u**3*a+3*u*u*t*b+3*u*t*t*c+t**3*d for a,b,c,d in zip(p0,p1,p2,p3))
def dbez(p0,p1,p2,p3,t):
    u=1-t
    return tuple(3*u*u*(b-a)+6*u*t*(c-b)+3*t*t*(d-c) for a,b,c,d in zip(p0,p1,p2,p3))

NIB=(50,204); C1=(92,168); C2=(150,96); TIP=(218,30)

def shaft_pt(t): return bez(NIB,C1,C2,TIP,t)
def normal(t):
    dx,dy=dbez(NIB,C1,C2,TIP,t); l=math.hypot(dx,dy); return (-dy/l, dx/l)  # zeigt nach links oben

def vane_polygon(t0=0.30, n=90):
    upper=[]; lower=[]
    for i in range(n+1):
        t=t0+(1-t0)*i/n
        s=(t-t0)/(1-t0)                 # 0 am Fahnenansatz, 1 an der Spitze
        env=math.sin(math.pi*min(1,s*1.05))**0.8
        wu=30*env*(1-0.25*s)            # breite Seite
        wd=14*env*(1-0.1*s)             # schmale Seite
        wu*=1-0.42*math.exp(-((s-0.50)/0.035)**2)  # sanfte Kerbe in der breiten Fahne
        x,y=shaft_pt(t); nx,ny=normal(t)
        upper.append((x+nx*wu, y+ny*wu)); lower.append((x-nx*wd, y-ny*wd))
    pts=upper+lower[::-1]
    return 'M'+' L'.join(f'{x:.2f} {y:.2f}' for x,y in pts)+' Z'

def barbs(t0=0.34, count=9):
    out=[]
    for k in range(count):
        t=t0+(0.95-t0)*k/(count-1); s=(t-0.30)/0.70
        env=math.sin(math.pi*min(1,s*1.05))**0.8
        x,y=shaft_pt(t); nx,ny=normal(t)
        dx,dy=dbez(NIB,C1,C2,TIP,t); l=math.hypot(dx,dy); tx,ty=dx/l,dy/l
        wu=26*env*(1-0.25*s); wd=12*env
        out.append(f'M{x:.2f} {y:.2f} L{x+nx*wu+tx*10:.2f} {y+ny*wu+ty*10:.2f}')
        out.append(f'M{x:.2f} {y:.2f} L{x-nx*wd+tx*6:.2f} {y-ny*wd+ty*6:.2f}')
    return out

def shaft_path():
    return f'M{NIB[0]} {NIB[1]} C{C1[0]} {C1[1]} {C2[0]} {C2[1]} {TIP[0]} {TIP[1]}'

def nib_path():
    x,y=NIB; tx,ty=dbez(NIB,C1,C2,TIP,0.0); l=math.hypot(tx,ty); tx,ty=tx/l,ty/l; nx,ny=-ty,tx
    a=(x+tx*16+nx*4, y+ty*16+ny*4); b=(x+tx*16-nx*4, y+ty*16-ny*4)
    return f'M{x-tx*2:.2f} {y-ty*2:.2f} L{a[0]:.2f} {a[1]:.2f} L{b[0]:.2f} {b[1]:.2f} Z'

def wave(x0,x1,y,amp0,amp1,periods,n=200):
    pts=[]
    for i in range(n+1):
        t=i/n; x=x0+(x1-x0)*t; a=amp0+(amp1-amp0)*(t**1.25)
        pts.append((x, y-a*math.sin(2*math.pi*periods*t)))
    return 'M'+' L'.join(f'{x:.2f} {yy:.2f}' for x,yy in pts)

def stars(w,h,count,seed,avoid=None,rmax=1.5,xoff=0,yoff=0):
    random.seed(seed); out=[]
    for _ in range(count):
        x=random.uniform(0,w); y=random.uniform(0,h)
        if avoid and avoid(x,y): continue
        r=random.uniform(0.5,rmax); o=random.uniform(0.25,0.85)
        out.append(f'<circle cx="{x+xoff:.1f}" cy="{y+yoff:.1f}" r="{r:.2f}" fill="#ffffff" opacity="{o:.2f}"/>')
    return '\n'.join(out)

DEFS='''<defs>
 <linearGradient id="bg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#26348a"/><stop offset="1" stop-color="#0a0f33"/></linearGradient>
 <linearGradient id="gold" x1="0" y1="1" x2="1" y2="0"><stop offset="0" stop-color="#f29d1e"/><stop offset="0.6" stop-color="#ffc94a"/><stop offset="1" stop-color="#fff0b8"/></linearGradient>
 <linearGradient id="teal" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#3fd3c1"/><stop offset="1" stop-color="#9af3e8"/></linearGradient>
 <radialGradient id="glow" cx="0.5" cy="0.5" r="0.5"><stop offset="0" stop-color="#7584ff" stop-opacity="0.35"/><stop offset="1" stop-color="#7584ff" stop-opacity="0"/></radialGradient>
</defs>'''

def mark():
    """Feder + Welle im 256er-Raster."""
    b='\n'.join(f'<path d="{d}" stroke="#a45f0c" stroke-width="1.3" stroke-linecap="round" opacity="0.5"/>' for d in barbs())
    return f'''<path d="{vane_polygon()}" fill="url(#gold)"/>
{b}
<path d="{shaft_path()}" stroke="#6b3a06" stroke-width="2.4" fill="none" stroke-linecap="round"/>
<path d="{nib_path()}" fill="#23160a"/>
<path d="{wave(NIB[0],238,NIB[1]+2,1.0,24,3.3)}" stroke="url(#teal)" stroke-width="7" fill="none" stroke-linecap="round" stroke-linejoin="round"/>'''

icon=f'''<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256" viewBox="0 0 256 256">
{DEFS}
<rect width="256" height="256" fill="url(#bg)"/>
<circle cx="150" cy="110" r="125" fill="url(#glow)"/>
{stars(256,256,26,11,avoid=lambda x,y: (x-130)**2/140**2+(y-120)**2/110**2<0.75, rmax=1.4)}
{mark()}
</svg>'''
open('icon.svg','w').write(icon)
