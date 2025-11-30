# Stellar Private Notes – End-to-End Encrypted Notes API
Built by Stellar Security (Switzerland)

Stellar Private Notes is a **zero-knowledge, end-to-end encrypted** notes system.  
All encryption and decryption happen **exclusively on the user’s device**.  
The server never sees plaintext notes, passwords, or encryption keys.

This repository contains the **Laravel 12 API** used by the mobile and web clients.

---

## 🔐 Key Principles

### **1. Zero-Knowledge Architecture**
- All notes are encrypted locally using a 32-byte Master Key (MK).
- The MK is wrapped (AES-GCM) using a Password Key (PK).
- Only the encrypted MK (“EAK”) is uploaded to the server.
- Server stores only:
    - Encrypted notes
    - Encrypted MK
    - KDF parameters + salt
    - User ID / timestamps

**Stellar Security cannot decrypt user notes.  
Only the user’s device can.**

### **2. Stellar ID is Optional**
Users can:
- Create a Stellar ID inside Stellar Notes, **or**
- Log in using an existing Stellar ID created elsewhere (Stellar Security website, apps, etc.)

If a user created their Stellar ID somewhere else, they may not initially have an EAK.  
The API includes a safe `updateEak` endpoint to attach a new EAK to an existing account.

No EAK = user has never used an E2EE product before.  
Once EAK is set, the account becomes fully compatible with Stellar Notes.

---

## 🚀 Features

### ✔ Full End-to-End Encryption (AES-GCM 256)
### ✔ PBKDF2 (SHA-256) with strong iteration count
### ✔ Zero plaintext secrets ever leave the device
### ✔ Server stores only opaque ciphertext blobs
### ✔ Secure, token-based authentication (Laravel Sanctum)
### ✔ Optional Stellar ID integration
### ✔ Optimized for mobile offline sync

---

## 🧠 How It Works (High-Level)

### 1. User logs in with password
API returns user metadata + EAK blob (if exists).

### 2. Client derives PK from password
Using:
```json
{
  "algo": "PBKDF2",
  "hash": "SHA-256",
  "iters": 210000,
  "salt": "<base64>"
}
```

### 3. Client decrypts EAK
Extracts the plaintext Master Key (MK), kept **only in RAM**.

### 4. Notes are encrypted
- Every note uses AES-GCM( MK, random 12-byte IV )
- Stored as `base64(iv || ciphertext)`

### 5. Server stores encrypted blobs only
It cannot decrypt notes.

---

## 📡 API Endpoints

### **Auth / Account (Stellar ID – optional)**
```
POST   /v1/logincontroller/create
POST   /v1/logincontroller/auth
PATCH  /v1/logincontroller/updateEak   ← attach EAK if missing
POST   /v1/logincontroller/sendresetpasswordlink
POST   /v1/logincontroller/resetpasswordupdate
```

### **Notes**
```
POST /v1/notescontroller/upload
POST /v1/notescontroller/sync-plan
POST /v1/notescontroller/download
POST /v1/notescontroller/find
```

---

## 🛡 Security Notes

- Laravel Sanctum tokens are used for stateless authentication.
- Tokens are stored only in secure storage on the client.
- User passwords are hashed using Laravel’s built-in hashing (bcrypt/argon2).
- EAK, MK, and all note contents are **never** logged or stored in plaintext.
- All encryption is done through WebCrypto on the client.

---

## ⚙ Deployment (Azure Ready)

This project is optimized for Azure App Service / Container Apps.

Important:  
Add this in `bootstrap/app.php` for trusting Azure proxies:

```php
$middleware->trustProxies(
    at: '*',
    headers: Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO
);
```

---

## 🏗 Tech Stack
- Laravel 12 (API)
- Sanctum (Token auth)
- MySQL / PostgreSQL
- Azure App Service / Swiss Region
- WebCrypto (on client)
- AES-GCM 256
- PBKDF2 SHA-256

---

## 🔒 Zero-Knowledge Guarantee

This project is designed so that:

- Stellar cannot decrypt user notes
- No plaintext keys are transmitted
- Only encrypted data lives on the server
- Crypto is open-source and verifiable

This is equivalent to industry-leading E2EE implementations like Signal, Proton, and Tresorit.

---

## 📄 License

Open-source, for educational and transparency purposes.  
Commercial usage permitted via Stellar SDK licensing.

---

## 💬 Contact

For security inquiries or audits:

**Stellar Security (Switzerland)**  
https://stellarsecurity.com

---

Made with ❤️ by Stellar Security  
