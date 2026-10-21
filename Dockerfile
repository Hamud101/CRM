FROM php:8.3-apache-bookworm

# Chromium renders the proposal PDFs, pypdf stamps the signature fields, and
# the fonts keep the quotes from falling back to a box glyph. pypdf comes from
# pip, pinned: Debian's 3.4.1 predates PdfWriter(clone_from=), which
# templates/sign_fields.py needs; 3.17.4 is what the shared host runs.
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      chromium python3 python3-pip fonts-dejavu-core fonts-liberation curl nano \
 && pip3 install --no-cache-dir --break-system-packages pypdf==3.17.4 \
 && rm -rf /var/lib/apt/lists/*

# The app's .htaccess needs AllowOverride, and the API sets a few headers.
RUN a2enmod rewrite headers
COPY docker/apache-crm.conf /etc/apache2/conf-available/crm.conf
RUN a2enconf crm

COPY docker/entrypoint.sh /usr/local/bin/crm-entrypoint
COPY docker/worker.sh /usr/local/bin/crm-worker
RUN chmod 755 /usr/local/bin/crm-entrypoint /usr/local/bin/crm-worker

COPY . /var/www/html/
# docker/ is only needed to build the image, and the code is read-only at run
# time: only the /data volume is writable.
RUN rm -rf /var/www/html/docker && chown -R root:root /var/www/html

ENV CRM_LOCAL=1 CRM_DATA_DIR=/data CRM_CHROME=/usr/bin/chromium CRM_PYTHON=/usr/bin/python3
VOLUME /data

# The app refuses any Host but localhost, so probe with the Host header set.
HEALTHCHECK --interval=30s --timeout=5s --retries=3 CMD curl -fsS -H 'Host: localhost' http://127.0.0.1/ >/dev/null || exit 1

ENTRYPOINT ["crm-entrypoint"]
CMD ["apache2-foreground"]
