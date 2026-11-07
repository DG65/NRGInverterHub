# InverterHub

[Deutsche Version: README.md](README.md)

![Symcon](https://img.shields.io/badge/Symcon-PHP%20module-blue)
![Module version](https://img.shields.io/badge/Version-0.82.0-informational)
![Symcon version](https://img.shields.io/badge/Symcon-%E2%89%A5%209.0-orange)
![License](https://img.shields.io/badge/License-PolyForm%20Noncommercial%201.0.0-lightgrey)
[![PayPal](https://img.shields.io/badge/PayPal-Donate-blue?logo=paypal)](https://paypal.me/DietmarGureth)

A Symcon module that reads and controls inverters from different manufacturers directly via
**Modbus TCP** — one generic driver framework instead of one module per manufacturer.

**Status: beta.** The register assignments are based on the manufacturers' publicly available
Modbus protocol documents and have been checked against real systems wherever possible (GoodWe
verified live; Fronius, SolarEdge, Kostal, Victron and Huawei tried out by beta testers on real
systems) as well as cross-checked against independent sources — the reference implementation
[OpenEMS](https://github.com/OpenEMS/openems) for register and field offsets (GoodWe, Fronius,
SMA), the register definitions of `huawei-solar-lib`, and Modbus templates shared by real users in
the [Symcon forum](https://community.symcon.de/c/symcon/vorlagen-modbus/86) (GoodWe, SolaX,
SolarEdge, Deye, Solplanet, Kostal, Victron, Huawei). Feedback on wrong or missing values is
welcome — please report manufacturer, model and the register concerned.

The module is bilingual: the console shows German or English depending on its language (form,
messages, variable names, status lists). Variable names are set when a variable is created and are
never overwritten afterwards, so you can rename them.

## Supported manufacturers

| Manufacturer | Scope | Note |
|---|---|---|
| **GoodWe** | PV (3 MPPT trackers), grid, battery 1+2, meter, energy, backup/island, EMS control | Verified live, in productive use |
| **Sungrow** | PV (4 MPPT), grid, battery, meter, energy, backup, start/stop | SH hybrid series |
| **Solis** | PV (4 strings), grid, battery, meter, energy | Hybrid series only (33000 registers); plain string inverters (3000 registers) not supported yet |
| **Growatt** | PV (3 strings), grid, energy, temperature, error codes | Covers the basic register range shared by TL-X/TL3-X/MOD/MIX/SPH/WIT |
| **SolaX** | PV (6 strings), grid single/three-phase, battery system values, meter/CT | **Important:** the inverter itself only speaks Modbus RTU. Modbus TCP works only via an additional SolaX monitoring module (Pocket WiFi/LAN) acting as a gateway — enter its IP address, not the inverter's |
| **SolarEdge** | PV total power, grid, meter (incl. import/feed-in kWh), energy, temperature, status, StorEdge battery, calculated PV generation | SunSpec, runtime discovery |
| **Deye** | PV (2 strings), grid, battery, house consumption, energy, start/stop control | SG04LP3 series, template tested on an 8K SG04LP3 |
| **Solplanet / AISWEI** | PV (3 strings), battery, temperature, energy | ASW Gen series |
| **Kostal** | PV (3 DC inputs), grid, battery, meter, house consumption by source, energy | Only PLENTICORE plus generation 1 tested — other generations/power classes unchecked. **Important:** choose the byte order matching the inverter |
| **SMA** | PV total power, PV inputs A/B, grid, meter, energy, temperature, status, device name/serial number | Pure SunSpec implementation with runtime discovery, as OpenEMS uses it for the SMA Sunny Tripower |
| **Fronius** | PV (MPPT: voltage/current/power per string), grid, meter (total + optionally per phase), energy, battery (GEN24 hybrid), status, device name/serial number | SunSpec, runtime discovery |
| **Victron GX** | PV (DC + AC-coupled, optionally per solar charge controller/MPPT), grid, battery, house consumption, grid source | Reads the aggregated system service |
| **Huawei SUN2000** | PV (DC input), grid, active power, temperature, status, energy, smart meter (DTSU666), battery (LUNA2000) | Native Huawei registers |
| **RCT Power Storage DC** | Battery SOC and power, grid, AC power, PV generator A/B, optionally battery values and total energies | **Preview, read only;** values and signs confirmed on an RCT Power Storage DC 10.0 (firmware 2.3.5689) |

Register addresses are in the **description field** of each variable (object manager, column
"Description") — handy for comparison with the manufacturer's manual or for your own scripts.

## Modules in this repository

### InverterHub

The actual data-reading instance. One module, one `Manufacturer` selection field — depending on
the selected manufacturer the matching data point groups (checkboxes) and registers are enabled.
Architecture:

- **`ModbusTcpClient`** — common Modbus TCP basics (read holding/input registers, write
  single/multiple, data type helpers), used by all drivers.
- **`InverterDriverInterface`** — the contract every manufacturer driver fulfills (base variables,
  optional groups, profiles, `readFast`/`readSlow`/`readDeviceInfo`/`writeControl`).
- **One driver per manufacturer** — encapsulates the manufacturer-specific register addresses,
  scaling factors and peculiarities.

Setup: create an instance, choose the manufacturer, enter the IP address (and port/unit ID if
needed), enable the desired data point groups, apply.

**External house load meter — input (optional):** under "External house load meter — input
(optional)" you can select an existing variable with the really measured house load (e.g. a Shelly
at the house connection). Important: choose a real consumption meter here (always positive), not a
grid/feed-in meter.

**Energy unit (kWh/Wh):** by default energy values are output in kWh. The switch "Output energy in
Wh instead of kWh" (in the data points panel) changes them to the base unit Wh — consistent with
power (W); the new Symcon presentation then scales itself to Wh/kWh/MWh. Existing instances stay
at kWh unless you switch (no jump in the history).

**Invert switches:** depending on wiring or the preferred convention, grid power (meter) and
battery power can be inverted by a switch. The displayed data point then follows the chosen
convention. Use them only if the device really reports a direction wrongly: they change the stored
variable itself, and other modules that read it adopt the changed direction.

### InverterHubDiscovery

A **configurator** module that searches an IP range in the local network for inverters on Modbus
TCP port 502:

1. Enter the start and end IP (preset from your own network when created, but editable),
   optionally a name template for the instances to be created.
2. Click "Search network" — non-blocking parallel scan of port 502.
3. For every open IP the manufacturer is recognized from a few documented default unit IDs and one
   characteristic register per manufacturer (no full 1-247 scan).
4. Hits appear in the result table — clicking "Create" creates an `InverterHub` instance with the
   IP address, unit ID and manufacturer already filled in.

**Ignore IPs:** addresses in this list are skipped completely during the search — intended for
RTU/TCP converters and other Modbus devices that would otherwise wrongly appear as inverters in the
result list (such devices forward Modbus requests to the bus behind them and therefore answer with
plausible values; they cannot be told apart reliably). Several IPs comma-separated.

**Name template:** leave empty for the default "manufacturer + running number" (e.g. "GoodWe 1",
"GoodWe 2"), or enter your own pattern with the placeholders `{hersteller}` `{ip}` `{unitid}`
`{nr}` (e.g. `{hersteller} Roof ({ip})`). The placeholder names stay German so that existing
templates keep working.

**Known limitation:** Filter/Refresh (above) and Create/Create all/target category selection
(below) of the result table are a fixed part of the native Symcon configurator view — their
position and a "mark individually as seen" cannot be influenced or added by the module (Symcon API
limit, no documentation available).

### InverterHubMqttBridge (Hoymiles MS-A2 via MQTT, preview)

A small add-on module ("NRG-Stack InverterHub Bridge (MQTT)") that connects InverterHub instances
to the **Symcon MQTT server**. The Hoymiles MS-A2 (storage) does not speak Modbus but sends via
MQTT.

Setup in the InverterHub instance: choose manufacturer **"Hoymiles MS-A2"** and connection type
**"MQTT"**, enter the **device ID** of the MS-A2 (e.g. `MSA-280520260806`, = prefix and serial
number), select the Symcon MQTT server, click **"Create MQTT bridge and connect"**, then
**Apply**. The device must send to the MQTT server of the Symcon machine (set access and port on
the device).

- **Read only and little control:** PV (incl. an attached inverter via `sys_pv2_p`), battery, SOC,
  grid, load and daily energies are read. The EMS mode (`general`, `mqtt_ctrl`, `tou_plan`) and a
  power setpoint can be controlled; the unit and sign of the setpoint are not documented (it is
  only passed through).
- **State:** mapping based on the source code of Lin071/ha and a capture (firmware V01.07.02),
  **not tried out on the device**. Please give feedback in the forum.
- **Why an instance of its own:** as with the gateway bridge, only the bridge carries the MQTT
  interfaces; the main module stays without a mandatory parent instance.

### InverterHubBridge (Symbox gateway, built-in RS485 port)

A small add-on module ("NRG-Stack InverterHub Bridge (ModBus gateway)") that connects an
InverterHub instance to a **native ModBus gateway** of Symcon, for example the built-in RS485 port
of the Symbox. External RTU-to-TCP gateways (e.g. Waveshare/USR) do not need it; they keep working
via "Direct" with IP address and port.

Setup in the InverterHub instance: choose connection type **"Symbox gateway"**, select the ModBus
gateway, click **"Create bridge and connect"** (creates the bridge, connects it to the gateway and
enters it), then **Apply**. An existing bridge on the same gateway is reused.

- **One bridge serves exactly one device.** The device address (unit ID) is set at the gateway as
  "Device ID", so IP address, port and unit ID are not needed in the InverterHub instance. A second
  device on the same bus needs a second gateway and a second bridge.
- **Why an instance of its own:** only the bridge carries the gateway interfaces. On the main
  module it triggered the note "requires a parent instance" for every direct connection.
- **Status:** if values are missing, the instance shows status 201 and names the reason: no bridge
  selected, bridge without gateway, gateway inactive, or no answer (then check the device ID at the
  gateway). The bridge shows the device ID read in its form.
- **State:** reading follows the scheme of Symcon's reference module but has not yet been
  confirmed widely on real hardware. Writing (control commands) is untested.
- **Not usable in gateway mode:** drivers that open a direct connection of their own for additional
  devices (meter with Fronius, SMA and SolarEdge, Victron).

### Tiles (`InverterHubTile`, `InverterHubEnergy`, `InverterHubMonitor`) — superseded

The task of the visualization (power flow tile, Sankey diagram, monitoring/diagnostics) is now
handled by [NRGDashboard](https://github.com/DG65/NRGDashboard). The three modules remain on
`beta`/`main` so that existing instances keep working. Their full documentation stays in the git
history of this repository.

## Fronius and SMA: note on SunSpec discovery

Both manufacturers speak the open SunSpec standard instead of registers of their own (documented
that way by Fronius itself — register addresses are **not constant** and depend on the respective
model chain; with SMA this module follows OpenEMS, which also addresses the SMA Sunny Tripower
purely via SunSpec). `FroniusDriver` and `SmaDriver` therefore walk the model chain from base
register 40000 in every read cycle (common block, then model ID + length per block) instead of
using fixed addresses. This is somewhat slower than with the other manufacturers but the most
reliable way.

## Related projects

Part of the **NRG-Stack** — the energy module family by DG65.

### EnergiePrognose (PV and consumption forecast)

**[Prognose](https://github.com/DG65/NRGPrognose)** supplies the forecasts against which the
measured values are compared. Of it, the module **PV forecast** (prefix `PVF`) is used. If the
forecast module is not installed, the expected values are omitted; everything else works
unchanged.

### MeterHub (energy meters)

**[MeterHub](https://github.com/DG65/NRGMeterHub)** is the sister repository of this project: the
same framework principle, but for **energy meters** instead of inverters (Modbus TCP, e.g. Siemens
PAC2200, Janitza UMG604/UMG800). Both projects can be used on their own and complement each other
where both are installed:

- **Device search:** `InverterHubDiscovery` can find both inverters and meters in one run and
  creates meters directly as MeterHub instances. If MeterHub is not installed, meters found are
  skipped.

The coupling is optional: neither module requires the other.

## Installation

Via the Symcon module control "Add" with the URL of this repository:

```
https://github.com/DG65/NRGInverterHub
```

For the beta channel select the branch `beta`.

### Working together with the other modules: how they find each other

All couplings are **optional** — no module requires another. If a partner is missing, only its
additional functions are omitted.

| Coupling | How it comes about |
|---|---|
| Device search → **MeterHub** | **automatic**: meters found are created as MeterHub instances |

### Recommended order

The order is not mandatory (nothing breaks) but saves rework:

1. Install **InverterHub**, create inverters — or let the **device search** find them
2. **MeterHub**, if energy meters are present
3. **NRGDashboard**, for the visualization (power flow, Sankey, monitoring/diagnostics)

### How many instances?

- **Inverters and meters:** as many as you like — one instance per device.
- **EMS:** exactly **one**. It is the only place that controls the battery; a second instance
  would work against the first.

## Contributing / reporting errors

Feedback on wrong register values, missing data points or newly supported models is welcome as an
issue on GitHub. Especially helpful: manufacturer, model name, the register/ident concerned and the
observed versus expected value.

## License

PolyForm Noncommercial 1.0.0, see [LICENSE](LICENSE). Free for private and non-commercial use;
commercial use requires a separate license.
