# The Outreach tab's Google Maps scraper: gosom/google-maps-scraper run from a
# small job loop. Its web mode never marks a finished job done (v1.18.1), so
# the loop drives the command line instead, which is reliable.
FROM gosom/google-maps-scraper:latest
COPY loop.sh /usr/local/bin/outreach-loop
RUN chmod 755 /usr/local/bin/outreach-loop
# gosom sends anonymous usage statistics unless told not to.
ENV DISABLE_TELEMETRY=1
ENTRYPOINT ["/usr/local/bin/outreach-loop"]
