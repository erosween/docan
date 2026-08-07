<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Struk {{ $outlet?->name }} — Docan</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#eee;color:#111;font:13px/1.35 ui-monospace,SFMono-Regular,Menlo,monospace}.receipt-tools{position:sticky;top:0;z-index:2;display:flex;justify-content:center;gap:8px;flex-wrap:wrap;padding:12px;background:#292438}.buyer-name-field{flex-basis:100%;max-width:460px;color:#fff;font:700 11px/1.4 system-ui,sans-serif}.buyer-name-field span{display:block;margin-bottom:6px}.buyer-name-field input{width:100%;height:44px;border:1px solid #ffffff30;border-radius:10px;background:#fff;color:#292438;padding:0 13px;font:600 14px system-ui,sans-serif;outline:0}.buyer-name-field input:focus{border-color:#f5c95f;box-shadow:0 0 0 3px #f5c95f35}.receipt-tools button{border:0;border-radius:9px;padding:10px 14px;font-weight:800}.receipt-tools .print{background:#f5c95f}.receipt-tools .bluetooth{background:#e9f3ff;color:#184d7a}.receipt-tools .bluetooth:disabled{opacity:.65}.printer-status{flex-basis:100%;margin:0;color:#eee;text-align:center;font:11px/1.4 system-ui,sans-serif}.printer-status.error{color:#ffb9b9}.printer-status.success{color:#baf2c7}.receipt{width:58mm;min-height:80mm;margin:18px auto;padding:4mm 3mm;background:#fff}.receipt h1{margin:0;text-align:center;font-size:17px}.receipt .meta{text-align:center;font-size:10px}.receipt .buyer{margin-top:7px;text-align:center;font-size:10px;overflow-wrap:anywhere}.rule{margin:8px 0;border-top:1px dashed #111}.item{margin:8px 0}.item b,.item small{display:block}.item small{font-size:10px}.line{display:grid;grid-template-columns:1fr auto;gap:8px}.totals{display:grid;grid-template-columns:1fr auto;gap:4px;font-weight:800}.thanks{text-align:center;margin:22px 0 4px}.receipt.wide{width:80mm}@media print{@page{size:58mm auto;margin:0}body{background:#fff}.receipt-tools{display:none}.receipt{width:58mm!important;margin:0;padding:3mm;box-shadow:none}body.wide-print .receipt{width:80mm!important}@page{margin:0}}
    </style>
</head>
<body>
    <div class="receipt-tools">
        <label class="buyer-name-field"><span>Nama pembeli <small>(opsional)</small></span><input
                id="buyer-name" type="text" maxlength="80" autocomplete="name"
                placeholder="Contoh: Budi"></label>
        <button type="button" onclick="setPaper(58)">58 mm</button>
        <button type="button" onclick="setPaper(80)">80 mm</button>
        <button class="print" type="button" onclick="window.print()">Cetak struk</button>
        <button class="bluetooth" id="bluetooth-print" type="button">Bluetooth ESC/POS</button>
        <p class="printer-status" id="printer-status">Printer yang sudah dipasangkan juga dapat dipilih lewat “Cetak struk”.</p>
    </div>
    <main class="receipt" id="receipt">
        <h1>{{ $outlet?->name ?? 'DOCAN' }}</h1>
        <div class="meta">Struk #{{ $transactions->pluck('id')->join('-') }}<br>{{ $transactions->first()->created_at->format('d/m/Y H:i') }}</div>
        <div class="buyer" id="receipt-buyer" hidden>Pembeli: <span></span></div>
        <div class="rule"></div>
        @foreach($transactions as $transaction)
            @php($unitPrice = intdiv((int)$transaction->price,max(1,(int)$transaction->quantity)))
            <section class="item">
                <b>{{ $transaction->product?->name ?? $transaction->product_type }}</b>
                <small>{{ $transaction->provider }}{{ $transaction->customer_number !== '-' ? ' · '.$transaction->customer_number : '' }}</small>
                <div class="line"><span>{{ number_format($transaction->quantity) }} × Rp {{ number_format($unitPrice,0,',','.') }}</span><strong>Rp {{ number_format($transaction->price,0,',','.') }}</strong></div>
            </section>
        @endforeach
        <div class="rule"></div>
        <div class="totals"><span>Total Qty</span><span>{{ number_format($totalQuantity) }}</span><span>TOTAL</span><span>Rp {{ number_format($total,0,',','.') }}</span></div>
        <div class="rule"></div>
        <p class="thanks">*** Terima kasih ***<br><small>Dicetak dengan Docan</small></p>
    </main>
    <script>
        function setPaper(size){document.getElementById('receipt').classList.toggle('wide',size===80);document.body.classList.toggle('wide-print',size===80)}

        const buyerNameInput = document.getElementById('buyer-name');
        const receiptBuyer = document.getElementById('receipt-buyer');
        buyerNameInput.addEventListener('input', () => {
            const name = buyerNameInput.value.trim().replace(/\s+/g, ' ');
            receiptBuyer.hidden = name === '';
            receiptBuyer.querySelector('span').textContent = name;
        });

        const bluetoothButton = document.getElementById('bluetooth-print');
        const printerStatus = document.getElementById('printer-status');
        const printerServices = [
            '000018f0-0000-1000-8000-00805f9b34fb',
            '0000ff00-0000-1000-8000-00805f9b34fb',
            '49535343-fe7d-4ae5-8fa9-9fafd205e455',
            'e7810a71-73ae-499d-8c15-faa9aef0c3f2',
        ];

        const updatePrinterStatus = (message, kind = '') => {
            printerStatus.textContent = message;
            printerStatus.className = `printer-status ${kind}`;
        };

        const printableReceipt = () => {
            const text = document.getElementById('receipt').innerText
                .replace(/[^\x0A\x0D\x20-\x7E]/g, '')
                .replace(/\n{3,}/g, '\n\n')
                .trim();
            return new Uint8Array([
                0x1b, 0x40,
                ...new TextEncoder().encode(`${text}\n\n\n`),
                0x1d, 0x56, 0x00,
            ]);
        };

        const findWritableCharacteristic = async (server) => {
            const services = await server.getPrimaryServices();
            for (const service of services) {
                const characteristics = await service.getCharacteristics();
                const writable = characteristics.find(characteristic =>
                    characteristic.properties.write || characteristic.properties.writeWithoutResponse
                );
                if (writable) return writable;
            }
            throw new Error('Karakteristik tulis ESC/POS tidak ditemukan.');
        };

        bluetoothButton.addEventListener('click', async () => {
            if (!navigator.bluetooth) {
                updatePrinterStatus('Bluetooth langsung belum didukung browser ini. Pasangkan printer di pengaturan perangkat lalu gunakan “Cetak struk”.', 'error');
                return;
            }

            bluetoothButton.disabled = true;
            updatePrinterStatus('Pilih printer thermal Bluetooth…');
            try {
                const device = await navigator.bluetooth.requestDevice({
                    acceptAllDevices: true,
                    optionalServices: printerServices,
                });
                updatePrinterStatus(`Menghubungkan ke ${device.name || 'printer'}…`);
                const server = await device.gatt.connect();
                const characteristic = await findWritableCharacteristic(server);
                const payload = printableReceipt();
                const chunkSize = 180;

                for (let offset = 0; offset < payload.length; offset += chunkSize) {
                    const chunk = payload.slice(offset, offset + chunkSize);
                    if (characteristic.properties.writeWithoutResponse) {
                        await characteristic.writeValueWithoutResponse(chunk);
                    } else {
                        await characteristic.writeValueWithResponse(chunk);
                    }
                }
                updatePrinterStatus(`Struk berhasil dikirim ke ${device.name || 'printer Bluetooth'}.`, 'success');
                server.disconnect();
            } catch (error) {
                if (error.name === 'NotFoundError') {
                    updatePrinterStatus('Pemilihan printer dibatalkan.', 'error');
                } else {
                    updatePrinterStatus(`${error.message || 'Printer Bluetooth tidak dapat dihubungkan.'} Gunakan “Cetak struk” sebagai alternatif.`, 'error');
                }
            } finally {
                bluetoothButton.disabled = false;
            }
        });
    </script>
</body>
</html>
