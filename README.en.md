# NRG-Stack ModbusServer

*Deutsche Fassung: [README.md](README.md)*

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Module version](https://img.shields.io/badge/Modul_Version-1.13.0-blue)
![Symcon version](https://img.shields.io/badge/Symcon_Version-9.0%2B-blue)
![License](https://img.shields.io/badge/License-PolyForm_Noncommercial_1.0.0-lightgrey)
[![Check Style](https://github.com/DG65/NRGModbusServer/actions/workflows/check-style.yml/badge.svg)](https://github.com/DG65/NRGModbusServer/actions/workflows/check-style.yml)
[![PayPal](https://img.shields.io/badge/PayPal-Me-blue?logo=paypal)](https://paypal.me/DietmarGureth)

Symcon library from the NRG-Stack that turns Symcon into a **Modbus TCP server (slave)**. External Modbus clients
(masters) read and write Symcon variables through a freely configurable register table.

**Part of the NRG-Stack** — the energy module family by DG65.

**Terminology:** For Modbus TCP, the Modbus specification speaks of *client* and *server*; older data sheets,
devices and tools (e.g. ModRSsim2) say *master* and *slave*. Both mean the same thing: this module is the
**server (slave)**, the polling remote side is the **client (master)**. Both terms appear in the text and the
user interface so that they can be compared with the device documentation.

Out of the box, Symcon itself only offers Modbus **client** functionality (master) – this module adds the
opposite direction. Typical uses:

- Provide Symcon measurements to a higher-level EMS, SCADA or control system
- Receive setpoints from external controllers, wallboxes or energy managers
- Emulate devices whose register layout is known (e.g. a meter or data logger) in order to connect
  systems that expect a specific Modbus participant
- Direct-marketing interfaces (see template below)

## Module: ModbusTCPServer

### How it works

The module sits as a device below a **Server Socket** instance (created automatically when the module is added;
set the port there freely, 502 is usual). Incoming Modbus TCP requests are buffered per client (TCP
fragmentation is handled correctly), answered against the register table and sent back directed to the
requesting client. **Several simultaneous clients on the same port are possible** – each connection gets its own
receive buffer.

To serve several separate interfaces (e.g. different ports or different register layouts per remote side),
create one instance with its own Server Socket per interface – one I/O per port matches the Symcon architecture.
The form action **"Create additional interfaces"** does the work for you: finish configuring one instance,
enter a port list (e.g. "501-505") – a copy including a Server Socket is created for every free port.

**Supported function codes:**

| FC | Function |
|----|----------|
| 03 | Read Holding Registers |
| 04 | Read Input Registers |
| 06 | Write Single Register |
| 16 | Write Multiple Registers |

**Data types:** uint16, int16, uint32, int32, float32, float64.
The word order for multi-word types can be switched (ABCD/CDAB).

**Register table:** Each row holds the address (0-based), area (Holding/Input), data type, Symcon variable,
current value (display only, see below), scaling factor, optional fixed value (if no variable is assigned; for a
memory cell, the start value) and write mode. The same variable may be mapped several times (e.g. float32 and
int32 representation in parallel).

**Write mode (column "Write"):**

| Mode | Behaviour |
|------|-----------|
| No | The master may only read; write attempts are acknowledged but discarded |
| Yes – action | `RequestAction` if the variable has an action (e.g. to switch an actuator), otherwise `SetValue` |
| Yes – direct | always `SetValue`; the variable's action is deliberately not triggered – required for variables of other instances (e.g. ModBus device registers) whose action would otherwise write to another device |

**Memory cells (row without a variable, Write set to "Yes"):** At its core, a Modbus server is a memory –
whatever the client writes into a register, it gets back on the next read. A writable row without an assigned
variable works exactly like that: the module remembers the written value (permanently, even after a restart) and
returns it on reads. The row's fixed value is only the **start value** until the first write access; "action" and
"direct" behave the same here. The remembered value is shown in the "Value" column; if the row is deleted or
assigned to a variable, it is discarded. The timeout protection also works with memory cells (the fallback value
is written into the cell, and a memory cell may serve as the source register for the duration).

Typical use: a direct marketer writes a setpoint, and a second device – for example a ModBus device in the same
Symcon installation – reads it back from this server and stores it in its own variable. Here, "Yes – direct" on
the ModBus device's variable would have no effect, because its variables are write-protected (`SetValue` from
outside fails with "Variable is marked as read-only"). With a memory cell, the ModBus device picks up the value by
polling – exactly as it used to from a simulator such as ModRSsim2, and all downstream events and scripts keep
working unchanged.

**Access times per register:** The register table additionally shows two columns, "Received" (last WRITTEN by a
master) and "Polled" (last READ by a master) – the time of day, or "–" if no access has happened since the last
restart. This shows per register whether and when traffic actually arrives, instead of only having the one global
variable "Last Modbus request". For your own scripts, `MBSLV_GetRegisterActivity` returns the same times as JSON
(`{"5000":{"w":1735900000,"r":1735899990}}`). The "Value" column also shows the current register content (scaled,
as transmitted over Modbus). All three columns update when the instance configuration is (re)opened –
refreshing an open form automatically is deliberately not implemented, because the register table is a saveable
list field and `UpdateFormField` on it prompts the console to "Apply changes" although nothing was changed. If
the module rejects a write attempt (e.g. because a register is not writable), the rejected value appears in the
debug log.

### Replacing an existing Modbus server (slave) (simulator, PLC)

If this module is to replace an existing Modbus server (e.g. a simulator such as ModRSsim2 through which a direct
marketer or control system has been connected so far):

1. **Reproduce the register table 1:1** – take over addresses, data types, word order and unit ID from the old
   server. If the client's unit ID is not known for sure, first switch off "Reject requests to other unit IDs"
   (the debug window shows the one actually used).
2. **Link the same Symcon variables** as before so that existing scripts and events keep running unchanged. If the
   variables belong to another instance (typically a ModBus device that has so far served the old server), choose
   write mode **"Yes – direct"**. If the previous variable is write-protected because a ModBus device reads the
   value back from the server, leave the variable empty (→ memory cell, see above).
3. **Switch over:** stop the old server on this port, then open this instance's Server Socket on the same port.
   If Symcon runs on a different machine than the old server, the client (or its VPN/NAT) must be changed over to
   the Symcon address.
4. **Check:** the connection line at the top of the form ("last … o'clock" keeps advancing at the client's polling
   interval) and the instance's debug window. You can go back at any time: close the Server Socket, start the old
   server.

**Unmapped registers:** If a master requests an address without a table row, the module returns either 0
(tolerant, default – useful when masters read whole blocks) or a Modbus exception "Illegal Data Address"
(strict).

**Status indicator:** The variable "Last Modbus request" shows the master's last sign of life. If the Server
Socket is deliberately closed (e.g. a prepared instance not yet in operation), the instance shows "Inactive" – not
an error. If the socket is supposed to run but does not reach an active state (e.g. port in use), or – with
communication monitoring enabled (minutes, 0 = off) – if no master is polling any more, the instance visibly
reports a real error status (no log access needed). Recommended for direct marketing (e.g. 5 min).

### Templates

Prepared register layouts can be loaded into the table with the popup button "Load template…" (including the
matching word order); nothing is saved until you click "Apply changes". Currently included:

- **Meteocontrol blue'Log RPC** (direct marketing, see below)
- **SunSpec inverter, three-phase** (Common Model 1 + Model 113, float32, base 40000) – emulates a SunSpec
  compliant inverter for loggers/park controllers; the float models need no scale factors. The text fields of the
  Common Model (manufacturer/serial number) return 0, the module does not support strings.
- **SunSpec meter, three-phase** (Common Model 1 + Model 213, float32, base 40000) – Symcon appears as a SunSpec
  grid meter to wallbox/EMS systems (e.g. evcc, openWB); the most important assignments are W (active power,
  export positive) and TotWhImp/TotWhExp. Layout generated from the official SunSpec model definition.

Further templates can be added following the same pattern.

**Create data points:** The module does not create variables for table rows on its own – the table normally points
to existing variables. The button **"Create data points for unassigned registers"** creates, where needed, a data
point below the instance for every row without a variable and enters it in the table (rows with a fixed value are
left untouched). These data points can then be filled from any source via event/script – handy for emulations such
as the SunSpec template. The button works on the **saved** register table – a row that has just been added but not
yet saved with "Apply changes" therefore only gets its data point after saving.

### Timeout protection (optional safeguard for writable registers)

Without the RPC template, the register table has **no expiry date** of its own for a written value – a setpoint,
once set, stays in place indefinitely if the master loses its connection. This can be a real safety problem, e.g.
if a direct marketer sets a curtailment and then loses the connection: without protection, the plant would stay
curtailed permanently instead of being released automatically after the agreed validity period.

In the panel **"⏱ Timeout protection"** you can add this per register (independent of whether the RPC template is
used at all):

| Column | Meaning |
|---|---|
| Target address | Register from the main table to be monitored (must be writable there) |
| Duration + unit | fixed validity period (seconds or minutes) |
| Source register | optional: address of another register whose **current value** supplies the duration instead of the fixed "Duration" – for masters that write their validity period themselves |
| Fallback value | is set as soon as the target address has not been written for longer than the duration |
| Status | live display: never written / current / in fallback / configuration error |

**Example Meteocontrol blue'Log:** Next Kraftwerke (or another direct marketer) writes the setpoint into register
5000 and the validity period into register 5006 (in minutes). A time-monitoring row with target address 5000,
source register 5006, unit minutes and fallback value 100 (= no curtailment) reproduces the blue'Log's own safety
behaviour – even without the RPC template, e.g. when register 5000 points to an existing, reused variable after
migrating an existing server (write mode "Yes - direct", see above).

The time only starts running with the **first real write access** – a row that has never been written never falls
back automatically, so restarting the instance does not trigger an unwanted fallback. The check runs once per
minute (same cycle as the status indicator); an active fallback additionally appears as a counter in the
connection header line at the top of the form.

#### Meteocontrol blue'Log RPC (direct marketing)

Emulates the **Remote Power Control (RPC) interface** of the Meteocontrol blue'Log XM/XC (data sheet as of
05-2020: unit ID 10, port 502, FC 03/16, word order low before high):

- **Register 5000** (float32, RW): active-power setpoint 0–125 %
- **Register 5002–5005** (float32, RW): reserve (stored and returned)
- **Register 5006** (float32, RW): validity period of the setpoint in minutes (1–255, default 10)
- **Register 5008** (float32, RW): watchdog – extends a running setpoint
- **Actual-value registers** 0–14 (float32), 100–114 (int32) and 4000 (P_AV) via the register table

The RPC interface is enabled via the template in the popup "Load template…" – only then does the settings panel
(fallback setpoint, validity period, event script) appear. The control registers 5000/5002–5005/5006/5008 are
managed **internally** by the module – they do not appear in the register table. The template loads the associated
actual-value registers.

**Expiry logic according to the data sheet:** A written setpoint is valid for the validity period; every further
setpoint or a watchdog write restarts the expiry timer. A watchdog **after** expiry does not keep the setpoint
alive – it has to be set again. After expiry, the effective setpoint falls back to the configurable **fallback
setpoint** (default 100 %).

**Variables with the RPC interface enabled** (names as created in the English UI; instances created in German use
the German names given in [README.md](README.md)):

| Variable | Meaning |
|----------|---------|
| Direct marketing setpoint | value last written by the master (register 5000) |
| Direct marketing setpoint valid | true while the validity period is running |
| Direct marketing setpoint valid until | expiry timestamp |
| **Effective direct marketing setpoint** | setpoint while valid, otherwise fallback setpoint – connect EMS/forwarding here |
| Validity period | current value of register 5006 |
| Last direct marketing setpoint | timestamp of the last write (setpoint or watchdog) |

**Forwarding to a real blue'Log** (Symcon as an intermediate layer): put an event on "Effective direct marketing
setpoint" or configure the optional event script (`$_IPS['Action']` = `setpoint` | `watchdog` | `expired`,
`$_IPS['Setpoint']`, `$_IPS['Valid']`) and write the value into register 5000 of the blue'Log via a Symcon
ModBus master instance.

### Setup

1. Install the module via the module control (GitHub URL) or the Module Store
2. Create the instance "NRG-Stack Modbus TCP Server" – the Server Socket is created automatically
3. Set the port on the Server Socket (e.g. 502) and activate the socket
4. Fill the register table (manually or via template), assign variables, apply

### Test from the command line

```
# Read holding registers (here: register 5000 as float32, unit 10, 0-based)
modpoll -m tcp -t4:float -r 5000 -a 10 -0 -1 <Symcon-IP>

# Write a value (here: 30 to register 5000)
modpoll -m tcp -t4:float -r 5000 -a 10 -0 -1 <Symcon-IP> 30
```

### Limits

- Coils/discrete inputs (FC 01/02/05/15) are deliberately not implemented
- PHP modules are not optimised for millisecond latency; uncritical for usual polling intervals (≥ 500 ms)
- Depending on the system, port 502 requires root privileges (alternatively use e.g. 1502)

## Tests

The protocol core (`libs/ModbusServer.php`) is Symcon-independent and testable with the PHP CLI, as is the module
itself (form construction, memory cells, German/English texts) through a small stub environment without Symcon:

```
php .tests/codec_test.php
php .tests/module_test.php
```
