# ISATU Visits Android app (formerly ISATU Visitor)

This folder is the native Kotlin/Jetpack Compose visitor application. It is separate
from the PHP staff website and uses the versioned mobile API in
`phone_tracker/api/v1`.

## Implemented in the Phase 5 foundation

- Visitor registration with one-time tracking consent, immediate sign-in, password
  recovery and sign-out. Signup does not require a separate verification code.
- Android Keystore-encrypted bearer-token storage. No database password or staff
  account is embedded in the app.
- The five offices and their accepting/unavailable state are loaded from the server.
- Walk-in registration creates an immediately usable QR pass when the destination is
  accepting visitors. Appointment booking uses only server-provided time slots and
  still requires office approval.
- Appointment list/detail, cancellation, rejection reasons and alternate-schedule
  responses.
- QR generation uses only the approved `qr_pass.payload` returned by the API. The raw
  technical token is neither modeled by the client nor printed in the interface.
- In-app notifications, read state and visitor profile editing.
- An Android 13+ notification-permission explanation and enable button on the home
  screen.
- Firebase Messaging token registration and appointment-update notifications when a
  valid Firebase client file is provided.
- Checked-in-only foreground GPS sharing with a persistent notification.
- Room-backed offline location queue, idempotent event IDs and WorkManager retry when
  connectivity returns.
- Poppins typography and the existing ISATU brand asset.
- A padded adaptive launcher icon so Android's circular and rounded masks do not crop
  the university seal.
- A branded mobile dashboard adapted from the pre-oral design, with three bottom tabs
  (Home, Visits, Book), header access to Notifications and Profile, QR-pass guidance,
  and standardized visit purposes.

## Open the project

1. Install the current stable Android Studio with Android SDK 37 and JDK 17 support.
2. In Android Studio choose **File > Open** and select the `visitor_app` folder—not
   the repository root and not `phone_tracker`.
3. Allow Gradle Sync to complete.
4. Start Apache and MySQL in XAMPP before testing API-backed screens.

The committed Gradle wrapper uses Gradle 9.6 and Android Gradle Plugin 9.4. The app has
`minSdk 26`, `targetSdk 37`, and package name `ph.edu.isatu.visitor`.

## Choose the correct API address

Android cannot use the browser's `localhost` address unless the server is on the same
Android device.

- Android Studio emulator: the default is already
  `http://10.0.2.2/visitor-management-system/phone_tracker/api/v1/`.
- Physical phone: connect the PC and phone to the same trusted Wi-Fi network, find the
  PC's IPv4 address with `ipconfig`, and add this to `visitor_app/local.properties`:

```properties
ISATU_API_BASE_URL=http://192.168.1.25/visitor-management-system/phone_tracker/api/v1/
```

Replace `192.168.1.25` with the PC's actual IPv4 address. Windows Firewall must permit
Apache on the private network. Cleartext HTTP is enabled only in the debug build; a
release build must use an HTTPS API URL.

`local.properties.example` contains both emulator and physical-phone examples. Android
Studio normally writes `sdk.dir` automatically during the first project sync.

Do not commit `local.properties`.

## Enable push notifications

1. Create an Android app in the institution's Firebase project using package
   `ph.edu.isatu.visitor`.
2. Download its `google-services.json` into `visitor_app/app/google-services.json`.
3. Keep that file uncommitted; it is already in `.gitignore`.
4. Configure the separate Firebase service account used by the PHP worker as described
   in `PHASE4_MOBILE_API.md`.
5. Build on a physical phone, sign in, and verify that `devices.php` registers the
   installation before running the PHP push worker.

Without `google-services.json`, all app features except production push delivery still
work. In-app notifications continue to load from the API.

## First end-to-end phone test

1. Register a visitor, accept the tracking consent, and confirm the app signs in.
2. Test a walk-in: select an accepting office, create the pass, and scan it in Security
   within one hour.
3. Test an appointment separately using a server-provided slot.
4. Approve it from the matching Office dashboard and confirm its QR pass appears.
5. Scan the appointment pass in Security within the allowed time window.
6. Refresh the visit on the phone, grant precise location permission, and tap
   **Start location sharing**.
7. Confirm that Security sees the visitor marker and that the Android tracking
   notification remains visible.
8. Complete the visit and confirm that sharing stops and later GPS uploads are rejected.

## Not ready for the app store yet

Before publishing, the team still needs a real Firebase client, institutional email
delivery, privacy/retention approval, a Play signing key, final app icon/screenshots,
physical-device testing, a privacy policy, Google Play Data safety answers and a small
campus pilot. Never commit signing keys, Firebase service-account files, or production
secrets.
