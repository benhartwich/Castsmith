# Plugin directory artwork

`quill.py` draws the icon (quill whose nib turns into a sound wave),
`banner.py` the banner. Both write SVG; render with librsvg:

    python3 quill.py && rsvg-convert -w 256 -h 256 -o icon-256x256.png icon.svg
    python3 banner.py && rsvg-convert -w 1544 -h 500 -o banner-1544x500.png banner.svg

The PNGs live in the `assets/` folder of the WordPress.org SVN repository.
