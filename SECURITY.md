# Security

The widget reads only local `cloudflared` diagnostic endpoints and local PF
state counters. It does not use a Cloudflare API token and does not contact the
Cloudflare API.

Do not publish your Cloudflare tunnel credential JSON, tunnel token,
`config.yml`, pfSense `config.xml`, or diagnostic output containing private
hostnames or addresses when reporting a problem.

Please report a suspected vulnerability privately to the repository owner
before opening a public issue containing sensitive details.
