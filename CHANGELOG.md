# Changelog

## 1.0.3 — 2026-09-16

- Add compatibility with the pfSense 2.7.2 dashboard widget request format.
- Validate widget keys locally on pfSense versions without `is_valid_widgetkey()`.
- Read Cloudflare Tunnel diagnostics and Prometheus metrics from loopback only.
- Show HA connections, edge locations, QUIC latency, requests, origin errors,
  packet events, live traffic rates, and a rolling five-minute graph.
- Retain a restorable installer under `/conf` for pfSense upgrades.
