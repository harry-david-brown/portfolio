# Local dev

    cd site
    php -r 'echo password_hash("choose-a-password", PASSWORD_DEFAULT), "\n";'
    # paste the result into api.php -> PASSWORD_HASH
    php -S localhost:8000
    # site:  http://localhost:8000/index.html
    # admin: http://localhost:8000/admin.html

`php -S` serves PHP, so admin.html finds api.php and runs in **api mode**.

# GitHub Pages demo

Pages is static — no PHP. api.php never answers, so admin.html falls back to
**local mode**: edits live in the tab, "Download works.json" gives you a file to
commit, and new photos download as resized 1600px JPEGs for `images/`.

Note: admin.html on Pages is publicly readable. It cannot write anything without
api.php, but if you'd rather it not be there at all, keep it out of the branch
you publish.
