/**
 * Generates the golden test vectors (request signature + CREATE2 address) used
 * by the PHPUnit suite. Vectors are derived directly from the authoritative
 * AinePay implementation logic:
 *   - Signature: reproduces the AinePay backend signer (Java URLEncoder semantics).
 *   - Address:   reproduces the AinePay wallet CREATE2 derivation (ethers logic).
 *
 * Usage (requires the `ethers` package, e.g. via a checkout that provides it):
 *   node tests/fixtures/gen-vectors.js > tests/fixtures/test-vectors.json
 */
const { ethers } = require('ethers');
const crypto = require('crypto');

// Well-known secret matching the upstream signature test fixtures.
const SECRET = 'sv_5n61GLATPVKKejONbmwQPg2LuXZwRlibgDuoDLUQzV4';

// ---- Exact reproduction of Java URLEncoder.encode(s, "UTF-8") ----
// Unencoded: A-Z a-z 0-9 - _ . *  ; space -> '+' ; everything else -> %XX (upper-case).
function javaUrlEncode(s) {
  let out = '';
  const bytes = Buffer.from(s, 'utf8');
  for (const b of bytes) {
    const c = String.fromCharCode(b);
    if (
      (b >= 0x41 && b <= 0x5a) || // A-Z
      (b >= 0x61 && b <= 0x7a) || // a-z
      (b >= 0x30 && b <= 0x39) || // 0-9
      c === '-' || c === '_' || c === '.' || c === '*'
    ) {
      out += c;
    } else if (c === ' ') {
      out += '+';
    } else {
      out += '%' + b.toString(16).toUpperCase().padStart(2, '0');
    }
  }
  return out;
}

// Reproduces the request signer: sort pairs (key asc, then value asc) -> encode + join -> append ts/window -> HMAC.
function sign(pairs, timestamp, recvWindow, secretKey) {
  const sorted = [...pairs].sort((a, b) =>
    a[0] < b[0] ? -1 : a[0] > b[0] ? 1 : a[1] < b[1] ? -1 : a[1] > b[1] ? 1 : 0,
  );
  const body = sorted
    .map(([k, v]) => javaUrlEncode(k) + '=' + javaUrlEncode(v == null ? '' : v))
    .join('&');
  const payload =
    (body.length === 0 ? '' : body + '&') +
    'timestamp=' + timestamp + '&recvWindow=' + recvWindow;
  const b64 = secretKey.startsWith('sv_') ? secretKey.slice(3) : secretKey;
  const key = Buffer.from(b64.replace(/-/g, '+').replace(/_/g, '/'), 'base64');
  const mac = crypto.createHmac('sha256', key).update(payload, 'utf8').digest('hex');
  return { payload, signature: mac };
}

// Notify verification: the body is a form string -> parse -> sort keys -> re-encode -> append ts/window -> HMAC.
function signNotify(bodyFields, timestamp, recvWindow, secretKey) {
  const sortedKeys = Object.keys(bodyFields).sort();
  const canonical = sortedKeys
    .map((k) => javaUrlEncode(k) + '=' + javaUrlEncode(bodyFields[k]))
    .join('&');
  const payload =
    (canonical.length === 0 ? '' : canonical + '&') +
    'timestamp=' + timestamp + '&recvWindow=' + recvWindow;
  const b64 = secretKey.startsWith('sv_') ? secretKey.slice(3) : secretKey;
  const key = Buffer.from(b64.replace(/-/g, '+').replace(/_/g, '/'), 'base64');
  const mac = crypto.createHmac('sha256', key).update(payload, 'utf8').digest('hex');
  return { canonical, payload, signature: mac };
}

// Legacy notify (body only, no ts/window) -- matches the upstream known vector 08fbca...
function signNotifyLegacy(bodyFields, secretKey) {
  const sortedKeys = Object.keys(bodyFields).sort();
  const canonical = sortedKeys
    .map((k) => javaUrlEncode(k) + '=' + javaUrlEncode(bodyFields[k]))
    .join('&');
  const b64 = secretKey.startsWith('sv_') ? secretKey.slice(3) : secretKey;
  const key = Buffer.from(b64.replace(/-/g, '+').replace(/_/g, '/'), 'base64');
  const mac = crypto.createHmac('sha256', key).update(canonical, 'utf8').digest('hex');
  return { canonical, signature: mac };
}

// ---- CREATE2 address (reproduces the wallet derivation) ----
function predictAddress(factory, impl, merchantId, userId, destination, version, chainId) {
  const normalizedImpl = impl.toLowerCase().replace(/^0x/, '');
  const initCodeHash = ethers.keccak256(
    '0x3d602d80600a3d3981f3363d3d373d3d3d363d73' + normalizedImpl + '5af43d82803e903d91602b57fd5bf3',
  );
  const salt = ethers.keccak256(
    ethers.AbiCoder.defaultAbiCoder().encode(
      ['bytes32', 'bytes32', 'uint256', 'address', 'uint256'],
      [
        ethers.keccak256(ethers.toUtf8Bytes(merchantId)),
        ethers.keccak256(ethers.toUtf8Bytes(userId)),
        BigInt(version),
        destination,
        BigInt(chainId),
      ],
    ),
  );
  const packed = ethers.keccak256(ethers.concat(['0xff', factory, salt, initCodeHash]));
  return { initCodeHash, salt, address: ethers.getAddress(ethers.dataSlice(packed, 12)) };
}

const out = { signature: {}, notify: {}, create2: {} };

// === Signature vectors ===
out.signature.secret = SECRET;
out.signature.cases = [];
function sc(name, pairs, ts, rw) {
  const r = sign(pairs, ts, rw, SECRET);
  out.signature.cases.push({ name, pairs, timestamp: ts, recvWindow: rw, payload: r.payload, signature: r.signature });
}
sc('no_params', [], '1000', '5000');
sc('simple_sorted', [['coin', 'USDT'], ['chain', 'ETH']], 'T', 'W');
sc('url_encoding_space', [['note', 'hello world']], 'T', 'W');
sc('known_secret', [['userId', '1000000001'], ['coin', 'USDT_ERC20']], '1761611071000', '60000');
sc('indexed_brackets', [['orderIds[1]', '222'], ['orderIds[0]', '111']], 'T', 'W');
sc('repeated_key', [['orderIds', '222'], ['orderIds', '111']], 'T', 'W');
sc('mixed', [['coin', 'USDT'], ['orderIds', 'bbb'], ['orderIds', 'aaa']], 'T', 'W');
sc('null_value', [['key', '']], 'T', 'W');
// Sample inline order parameters (POST /pay). The signer treats every value as
// an opaque string, so these are deterministic placeholders, not the runtime
// format — at runtime userId is a sha256 hash (see Ainepay_Order_Helper).
sc('sample_pay_order',
  [['orderId', 'wc_a1b2c3_1042'], ['userId', 'sample_user_1042'], ['coin', 'USDT'], ['chain', 'ETH'], ['qty', '88.00']],
  '1760000300000', '60000');

// === Notify vectors ===
const notifyBody = {
  coin: 'USDT', created: '1767793122000', expired: '1767793122000',
  merchantId: '1001', orderId: 'order123', qty: '1.11',
  status: 'PAID', updated: '1767793122000', userId: 'user_001',
};
out.notify.secret = SECRET;
out.notify.legacy_known_vector = signNotifyLegacy(notifyBody, SECRET); // expected to equal upstream 08fbca...
const ts = '1760000300000', rw = '5000';
out.notify.with_timestamp = { ...signNotify(notifyBody, ts, rw, SECRET), timestamp: ts, recvWindow: rw, body: notifyBody };

// === CREATE2 vectors ===
out.create2.official_test = predictAddress(
  '0x1111111111111111111111111111111111111111',
  '0x2222222222222222222222222222222222222222',
  'merchant-demo', 'user-demo',
  '0x3333333333333333333333333333333333333333', 1, 1,
);
// Expected to equal the upstream spec value 0x601869574A7b726aA1Ac1833C49f3932F8d525c4.
out.create2.mainnet_example = predictAddress(
  '0x06559ab75cd906e2ecd9c3e91459eea558e2ec1b',
  '0x42eb2a5b755551d5f386f2c79807abd438341557',
  '20001', 'guest_order_1042',
  '0x000000000000000000000000000000000000dEaD', 1, 1,
);

console.log(JSON.stringify(out, null, 2));
