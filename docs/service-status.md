# Optional Services Status integration

The widget does not require `cloudflared` to be registered in pfSense's
**Services Status** list. This optional integration adds process status and
manual start, stop, and restart controls.

These instructions assume:

- one `cloudflared` connector runs directly on pfSense;
- its configuration is `/usr/local/etc/cloudflared/config.yml`;
- an existing Shellcmd entry starts the connector at boot; and
- replacing `<TUNNEL-UUID>` below with the actual tunnel UUID is acceptable.

Keep the existing boot-time Shellcmd. The service entry is the control layer;
it does not replace the boot launcher.

Run the following from a pfSense root shell after replacing the placeholder:

```sh
/usr/local/bin/php -r 'require_once("/etc/inc/config.inc"); $name="cloudflared"; $uuid="<TUNNEL-UUID>"; $services=config_get_path("installedpackages/service", []); $entry=["name"=>$name,"executable"=>"cloudflared","description"=>"Cloudflare Tunnel","startcmd"=>"mwexec(\"/usr/bin/killall cloudflared\"); mwexec_bg(\"/usr/sbin/daemon -f /usr/local/bin/cloudflared tunnel --config /usr/local/etc/cloudflared/config.yml run {$uuid}\");","stopcmd"=>"mwexec(\"/usr/bin/killall cloudflared\");"]; $found=false; foreach ($services as $i=>$service) { if (($service["name"] ?? "") === $name) { $services[$i]=$entry; $found=true; break; } } if (!$found) { $services[]=$entry; } config_set_path("installedpackages/service", $services); write_config("Register Cloudflare Tunnel service controls"); echo "cloudflared service controls registered\n";'
```

The start action deliberately terminates any surviving local `cloudflared`
process before launching the connector. This prevents duplicate connectors
when pfSense invokes Start or Restart. Do not use this example on a firewall
that intentionally runs multiple `cloudflared` processes.

Verify after using Restart:

```sh
pgrep -laf cloudflared
cloudflared tunnel info <TUNNEL-UUID>
```

There should be one local process. Cloudflare may show the previous connector
briefly while it ages out of the control plane.
