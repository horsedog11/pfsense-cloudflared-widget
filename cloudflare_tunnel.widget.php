<?php

/*
 * Native pfSense dashboard widget for a locally running cloudflared tunnel.
 *
 * Data sources:
 *   - cloudflared loopback metrics and diagnostic endpoints
 *   - pf state counters for UDP/TCP destination port 7844
 *
 * No Cloudflare API token is used or required.
 */

require_once("guiconfig.inc");
require_once("/usr/local/www/widgets/include/cloudflare_tunnel.inc");

function cftw_http_get($url) {
	$context = stream_context_create([
		"http" => [
			"timeout" => 0.75,
			"ignore_errors" => true,
		],
	]);

	$data = @file_get_contents($url, false, $context);
	return ($data === false) ? null : $data;
}

function cftw_metric_scalar($metrics, $name) {
	$pattern = '/^' . preg_quote($name, '/') . '\\s+([-+0-9.eE]+)\\s*$/m';
	if (preg_match($pattern, $metrics, $matches)) {
		return (float)$matches[1];
	}
	return null;
}

function cftw_label_value($labels, $name) {
	$pattern = '/(?:^|,)' . preg_quote($name, '/') . '="([^"]*)"/';
	if (preg_match($pattern, $labels, $matches)) {
		return $matches[1];
	}
	return null;
}

function cftw_state_age_seconds($age) {
	$parts = array_map('intval', explode(':', $age));
	if (count($parts) !== 3) {
		return 0;
	}
	return ($parts[0] * 3600) + ($parts[1] * 60) + $parts[2];
}

function cftw_collect_pf_states() {
	$lines = [];
	$return_code = 0;
	@exec('/sbin/pfctl -ss -vv 2>/dev/null', $lines, $return_code);

	$result = [
		"state_count" => 0,
		"udp_state_count" => 0,
		"tcp_state_count" => 0,
		"bytes_out" => 0,
		"bytes_in" => 0,
		"packets_out" => 0,
		"packets_in" => 0,
		"oldest_state_seconds" => 0,
		"remote_addresses" => [],
	];

	if ($return_code !== 0) {
		return $result;
	}

	for ($index = 0; $index < count($lines); $index++) {
		$line = $lines[$index];
		if (!preg_match('/^all\\s+(udp|tcp)\\s+.+?\\s+->\\s+([0-9a-fA-F:.]+):7844\\s+/i', $line, $state_match)) {
			continue;
		}

		$protocol = strtolower($state_match[1]);
		$remote_address = $state_match[2];
		$result["state_count"]++;
		$result[$protocol . "_state_count"]++;
		$result["remote_addresses"][] = $remote_address;

		if (!isset($lines[$index + 1])) {
			continue;
		}

		$detail = $lines[$index + 1];
		if (preg_match('/age\\s+([0-9]+:[0-9]{2}:[0-9]{2})/', $detail, $age_match)) {
			$result["oldest_state_seconds"] = max(
				$result["oldest_state_seconds"],
				cftw_state_age_seconds($age_match[1])
			);
		}

		if (preg_match('/([0-9]+):([0-9]+)\\s+pkts,\\s+([0-9]+):([0-9]+)\\s+bytes/', $detail, $counter_match)) {
			$result["packets_out"] += (int)$counter_match[1];
			$result["packets_in"] += (int)$counter_match[2];
			$result["bytes_out"] += (int)$counter_match[3];
			$result["bytes_in"] += (int)$counter_match[4];
		}
	}

	$result["remote_addresses"] = array_values(array_unique($result["remote_addresses"]));
	return $result;
}

function cftw_collect() {
	$metrics_port = null;
	$metrics = null;
	$diag = null;

	foreach (range(20241, 20245) as $port) {
		$candidate_metrics = cftw_http_get("http://127.0.0.1:{$port}/metrics");
		if (($candidate_metrics === null) || (strpos($candidate_metrics, 'cloudflared_tunnel_') === false)) {
			continue;
		}

		$metrics_port = $port;
		$metrics = $candidate_metrics;
		$diag_raw = cftw_http_get("http://127.0.0.1:{$port}/diag/tunnel");
		if ($diag_raw !== null) {
			$decoded = json_decode($diag_raw, true);
			if (is_array($decoded)) {
				$diag = $decoded;
			}
		}
		break;
	}

	$pf = cftw_collect_pf_states();
	$connections = [];
	$all_connected = true;
	$tunnel_id = null;
	$connector_id = null;

	if (is_array($diag)) {
		$tunnel_id = $diag['tunnelID'] ?? null;
		$connector_id = $diag['connectorID'] ?? null;
		foreach (($diag['connections'] ?? []) as $offset => $connection) {
			$connection_index = isset($connection['index']) ? (int)$connection['index'] : (int)$offset;
			$is_connected = !empty($connection['isConnected']);
			$all_connected = $all_connected && $is_connected;
			$connections[(string)$connection_index] = [
				"index" => $connection_index,
				"connected" => $is_connected,
				"protocol_id" => isset($connection['protocol']) ? (int)$connection['protocol'] : null,
				"edge_address" => $connection['edgeAddress'] ?? null,
				"edge_location" => null,
				"latest_rtt_ms" => null,
				"smoothed_rtt_ms" => null,
			];
		}
	}

	$locations = [];
	$latest_rtts = [];
	$smoothed_rtts = [];
	$lost_packets = 0;
	$lost_packet_reasons = [];
	$version = null;

	if ($metrics !== null) {
		if (preg_match_all('/^cloudflared_tunnel_server_locations\\{([^}]*)\\}\\s+1(?:\\.0+)?\\s*$/m', $metrics, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $match) {
				$connection_index = cftw_label_value($match[1], 'connection_id');
				$location = cftw_label_value($match[1], 'edge_location');
				if ($location === null) {
					continue;
				}
				$locations[$location] = ($locations[$location] ?? 0) + 1;
				if (($connection_index !== null) && isset($connections[$connection_index])) {
					$connections[$connection_index]['edge_location'] = $location;
				}
			}
		}

		if (preg_match_all('/^quic_client_latest_rtt\\{([^}]*)\\}\\s+([-+0-9.eE]+)\\s*$/m', $metrics, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $match) {
				$connection_index = cftw_label_value($match[1], 'conn_index');
				$value = (float)$match[2];
				$latest_rtts[] = $value;
				if (($connection_index !== null) && isset($connections[$connection_index])) {
					$connections[$connection_index]['latest_rtt_ms'] = $value;
				}
			}
		}

		if (preg_match_all('/^quic_client_smoothed_rtt\\{([^}]*)\\}\\s+([-+0-9.eE]+)\\s*$/m', $metrics, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $match) {
				$connection_index = cftw_label_value($match[1], 'conn_index');
				$value = (float)$match[2];
				$smoothed_rtts[] = $value;
				if (($connection_index !== null) && isset($connections[$connection_index])) {
					$connections[$connection_index]['smoothed_rtt_ms'] = $value;
				}
			}
		}

		if (preg_match_all('/^quic_client_lost_packets\\{([^}]*)\\}\\s+([-+0-9.eE]+)\\s*$/m', $metrics, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $match) {
				$value = (int)round((float)$match[2]);
				$reason = cftw_label_value($match[1], 'reason') ?? 'unknown';
				$lost_packets += $value;
				$lost_packet_reasons[$reason] = ($lost_packet_reasons[$reason] ?? 0) + $value;
			}
		}

		if (preg_match('/^build_info\\{([^}]*)\\}\\s+1(?:\\.0+)?\\s*$/m', $metrics, $build_match)) {
			$version = cftw_label_value($build_match[1], 'version');
		}
	}

	$active_connections = ($metrics === null) ? null : cftw_metric_scalar($metrics, 'cloudflared_tunnel_ha_connections');
	$expected_connections = count($connections);
	if ($expected_connections === 0) {
		$expected_connections = 4;
	}

	if (($metrics === null) || ($active_connections === null) || ((int)$active_connections === 0)) {
		$status = 'down';
	} elseif (((int)$active_connections < $expected_connections) || !$all_connected) {
		$status = 'degraded';
	} else {
		$status = 'healthy';
	}

	if ($pf['udp_state_count'] > 0) {
		$transport = 'QUIC / UDP 7844';
	} elseif ($pf['tcp_state_count'] > 0) {
		$transport = 'HTTP/2 / TCP 7844';
	} else {
		$transport = 'No PF states';
	}

	ksort($locations);
	ksort($connections, SORT_NUMERIC);

	return [
		"timestamp_ms" => (int)round(microtime(true) * 1000),
		"status" => $status,
		"metrics_port" => $metrics_port,
		"tunnel_id" => $tunnel_id,
		"connector_id" => $connector_id,
		"version" => $version,
		"active_connections" => ($active_connections === null) ? 0 : (int)$active_connections,
		"expected_connections" => $expected_connections,
		"transport" => $transport,
		"locations" => $locations,
		"connections" => array_values($connections),
		"requests_total" => ($metrics === null) ? null : cftw_metric_scalar($metrics, 'cloudflared_tunnel_total_requests'),
		"request_errors" => ($metrics === null) ? null : cftw_metric_scalar($metrics, 'cloudflared_tunnel_request_errors'),
		"active_streams" => ($metrics === null) ? null : cftw_metric_scalar($metrics, 'cloudflared_tunnel_active_streams'),
		"latest_rtt_min_ms" => empty($latest_rtts) ? null : min($latest_rtts),
		"latest_rtt_max_ms" => empty($latest_rtts) ? null : max($latest_rtts),
		"smoothed_rtt_min_ms" => empty($smoothed_rtts) ? null : min($smoothed_rtts),
		"smoothed_rtt_max_ms" => empty($smoothed_rtts) ? null : max($smoothed_rtts),
		"quic_events_total" => $lost_packets,
		"quic_event_reasons" => $lost_packet_reasons,
		"pf" => $pf,
	];
}

if (isset($_GET['ajax'])) {
	$requested_widgetkey = $_GET['widgetkey'] ?? null;
	if (($requested_widgetkey === null) || !preg_match('/^cloudflare_tunnel-[0-9]+$/', $requested_widgetkey)) {
		http_response_code(400);
		header('Content-Type: application/json');
		echo json_encode(["error" => "Invalid widget key"]);
		exit;
	}
	header('Content-Type: application/json');
	header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
	echo json_encode(cftw_collect(), JSON_UNESCAPED_SLASHES);
	exit;
}

?>
<style>
.cftw-summary { margin-bottom: 8px; }
.cftw-status { font-weight: 600; letter-spacing: .02em; }
.cftw-status.healthy { color: #2b8a3e; }
.cftw-status.degraded { color: #d97706; }
.cftw-status.down { color: #c92a2a; }
.cftw-dot { display: inline-block; width: 9px; height: 9px; border-radius: 50%; margin-right: 6px; background: #868e96; }
.cftw-status.healthy .cftw-dot { background: #2f9e44; box-shadow: 0 0 0 3px rgba(47,158,68,.12); }
.cftw-status.degraded .cftw-dot { background: #f59f00; box-shadow: 0 0 0 3px rgba(245,159,0,.12); }
.cftw-status.down .cftw-dot { background: #e03131; box-shadow: 0 0 0 3px rgba(224,49,49,.12); }
.cftw-table { margin-bottom: 6px !important; }
.cftw-table td { padding: 4px 6px !important; vertical-align: middle !important; }
.cftw-table td:first-child { width: 34%; color: #586069; }
.cftw-traffic { display: flex; gap: 16px; margin: 6px 0 2px; font-variant-numeric: tabular-nums; }
.cftw-up { color: #d96d00; }
.cftw-down { color: #1971c2; }
.cftw-chart { width: 100%; height: 66px; display: block; }
.cftw-legend { display: flex; justify-content: space-between; color: #6c757d; font-size: 11px; }
.cftw-error { margin: 6px 0 0; display: none; }
</style>

<div id="cftw-root">
	<div class="cftw-summary">
		<span id="cftw-status" class="cftw-status">
			<span class="cftw-dot"></span><span id="cftw-status-text">Loading</span>
		</span>
	</div>

	<table class="table table-condensed cftw-table">
		<tbody>
			<tr><td>Connections</td><td id="cftw-connections">—</td></tr>
			<tr><td>Edges</td><td id="cftw-edges">—</td></tr>
			<tr><td>Latency</td><td id="cftw-latency">—</td></tr>
			<tr><td>Requests</td><td id="cftw-requests">—</td></tr>
			<tr><td>Origin errors</td><td id="cftw-errors">—</td></tr>
			<tr><td>QUIC events</td><td id="cftw-quic-events">—</td></tr>
		</tbody>
	</table>

	<div class="cftw-traffic">
		<span class="cftw-up">↑ <strong id="cftw-rate-up">sampling…</strong></span>
		<span class="cftw-down">↓ <strong id="cftw-rate-down">sampling…</strong></span>
	</div>
	<canvas id="cftw-chart" class="cftw-chart" aria-label="Cloudflare Tunnel traffic history"></canvas>
	<div class="cftw-legend">
		<span id="cftw-total-up">Sent —</span>
		<span id="cftw-updated">Waiting for data</span>
		<span id="cftw-total-down">Received —</span>
	</div>
	<div id="cftw-error" class="alert alert-warning cftw-error"></div>
</div>

<script type="text/javascript">
//<![CDATA[
(function() {
	"use strict";

	var previous = null;
	var historyUp = [];
	var historyDown = [];
	var timer = null;
	var maxPoints = 60;
	var widgetKey = <?=json_encode($widgetkey)?>;

	function element(id) {
		return document.getElementById(id);
	}

	function numberOrDash(value) {
		return (value === null || typeof value === "undefined") ? "—" : Number(value).toLocaleString();
	}

	function formatBytes(value) {
		var units = ["B", "KB", "MB", "GB", "TB"];
		var size = Math.max(0, Number(value) || 0);
		var unit = 0;
		while (size >= 1000 && unit < units.length - 1) {
			size /= 1000;
			unit++;
		}
		return size.toFixed(unit === 0 ? 0 : (size >= 100 ? 0 : 1)) + " " + units[unit];
	}

	function formatRate(bytesPerSecond) {
		var bits = Math.max(0, Number(bytesPerSecond) || 0) * 8;
		var units = ["b/s", "Kb/s", "Mb/s", "Gb/s"];
		var unit = 0;
		while (bits >= 1000 && unit < units.length - 1) {
			bits /= 1000;
			unit++;
		}
		return bits.toFixed(bits >= 100 ? 0 : (bits >= 10 ? 1 : 2)) + " " + units[unit];
	}

	function drawChart() {
		var canvas = element("cftw-chart");
		if (!canvas) {
			return;
		}
		var ratio = window.devicePixelRatio || 1;
		var width = Math.max(260, canvas.clientWidth || 300);
		var height = Math.max(60, canvas.clientHeight || 66);
		canvas.width = Math.floor(width * ratio);
		canvas.height = Math.floor(height * ratio);
		var context = canvas.getContext("2d");
		context.scale(ratio, ratio);
		context.clearRect(0, 0, width, height);

		context.strokeStyle = "rgba(128,128,128,.18)";
		context.lineWidth = 1;
		context.beginPath();
		context.moveTo(0, height - .5);
		context.lineTo(width, height - .5);
		context.stroke();

		var combined = historyUp.concat(historyDown);
		var maximum = Math.max.apply(null, combined.concat([1]));

		function line(values, color) {
			if (values.length < 2) {
				return;
			}
			context.strokeStyle = color;
			context.lineWidth = 2;
			context.lineJoin = "round";
			context.beginPath();
			values.forEach(function(value, index) {
				var x = (index / Math.max(1, maxPoints - 1)) * width;
				var y = height - 3 - ((value / maximum) * (height - 8));
				if (index === 0) {
					context.moveTo(x, y);
				} else {
					context.lineTo(x, y);
				}
			});
			context.stroke();
		}

		line(historyUp, "#f48120");
		line(historyDown, "#1971c2");
	}

	function update(data) {
		var status = element("cftw-status");
		status.className = "cftw-status " + data.status;
		element("cftw-status-text").textContent = data.status.charAt(0).toUpperCase() + data.status.slice(1);
		element("cftw-connections").textContent = data.active_connections + " / " + data.expected_connections + " · " + data.transport;

		var locations = Object.keys(data.locations || {}).map(function(location) {
			return (data.locations[location] > 1 ? data.locations[location] + "× " : "") + location.toUpperCase();
		});
		element("cftw-edges").textContent = locations.length ? locations.join(" · ") : "—";

		if (data.smoothed_rtt_min_ms !== null) {
			element("cftw-latency").textContent = Math.round(data.smoothed_rtt_min_ms) + "–" + Math.round(data.smoothed_rtt_max_ms) + " ms";
		} else {
			element("cftw-latency").textContent = "—";
		}

		element("cftw-requests").textContent = numberOrDash(data.requests_total);
		element("cftw-errors").textContent = numberOrDash(data.request_errors);
		var reasons = Object.keys(data.quic_event_reasons || {}).map(function(reason) {
			return reason + " " + numberOrDash(data.quic_event_reasons[reason]);
		});
		element("cftw-quic-events").textContent = numberOrDash(data.quic_events_total) + (reasons.length ? " · " + reasons.join(", ") : "");

		var now = Number(data.timestamp_ms);
		var currentOut = Number(data.pf.bytes_out);
		var currentIn = Number(data.pf.bytes_in);
		if (previous && now > previous.time && currentOut >= previous.out && currentIn >= previous.in) {
			var seconds = (now - previous.time) / 1000;
			var rateOut = (currentOut - previous.out) / seconds;
			var rateIn = (currentIn - previous.in) / seconds;
			element("cftw-rate-up").textContent = formatRate(rateOut);
			element("cftw-rate-down").textContent = formatRate(rateIn);
			historyUp.push(rateOut);
			historyDown.push(rateIn);
			if (historyUp.length > maxPoints) { historyUp.shift(); }
			if (historyDown.length > maxPoints) { historyDown.shift(); }
			drawChart();
		} else {
			element("cftw-rate-up").textContent = "sampling…";
			element("cftw-rate-down").textContent = "sampling…";
		}
		previous = { time: now, out: currentOut, in: currentIn };

		element("cftw-total-up").textContent = "Sent " + formatBytes(currentOut);
		element("cftw-total-down").textContent = "Received " + formatBytes(currentIn);
		element("cftw-updated").textContent = "Updated " + new Date(now).toLocaleTimeString();
		element("cftw-error").style.display = "none";
	}

	function schedule() {
		window.clearTimeout(timer);
		timer = window.setTimeout(poll, 5000);
	}

	function poll() {
		if (document.hidden) {
			schedule();
			return;
		}
		fetch("/widgets/widgets/cloudflare_tunnel.widget.php?ajax=1&widgetkey=" + encodeURIComponent(widgetKey) + "&_=" + Date.now(), {
			credentials: "same-origin",
			cache: "no-store"
		}).then(function(response) {
			if (!response.ok) {
				throw new Error("HTTP " + response.status);
			}
			return response.json();
		}).then(function(data) {
			update(data);
		}).catch(function(error) {
			var warning = element("cftw-error");
			warning.textContent = "Unable to refresh tunnel data: " + error.message;
			warning.style.display = "block";
		}).then(schedule);
	}

	function start() {
		poll();
		window.addEventListener("resize", drawChart);
	}

	if (typeof events !== "undefined" && events.push) {
		events.push(start);
	} else if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", start);
	} else {
		start();
	}
})();
//]]>
</script>
