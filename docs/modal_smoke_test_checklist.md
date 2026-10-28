# Modal UI Smoke Test Checklist

This checklist is used to manually verify that all modal dialogs in the **Absensi Anak** application behave correctly after the recent fixes.

## General Rules
- Open the modal.
- Perform a scroll interaction inside the modal content.
- Verify that the background page does **not** scroll.
- Verify that the modal stays fully visible (no flickering or disappearing).
- Close the modal and ensure the page scroll returns to normal.

## Pages & Modals to Test
| Page (URL) | Modal Trigger | Expected Behaviour |
|------------|--------------|--------------------|
| `/public/profile.php` | **Edit Profile Photo** button | Modal stays fixed, scroll lock enabled, no flicker. |
| `/public/account.php` | **Change Password** button | Same as above. |
| `/public/supervisi/schedules.php` | **Tambah Jadwal Supervisi** button | Modal body scrollable, overlay has `overflow-y: auto`, no flicker. |
| `/public/supervisi/peer.php` | **Tambah Observers** button | Modal layout uses flex, scroll works, overlay stable. |
| `/public/supervisi/principal.php` | **Izin Sejawat Kepsek** button | Modal renders correctly, scroll works, no flicker. |
| Any page with **Add/Edit** actions (e.g., `student.php`, `teacher.php`) | Corresponding **Add/Edit** modal | Consistent behaviour as above. |

## Verification Steps
1. Navigate to each URL.
2. Click the trigger to open the modal.
3. Scroll the modal content up and down.
4. Observe the modal background (should be a solid dark overlay, no blur).
5. Ensure the modal does not disappear when the page is scrolled.
6. Close the modal and try scrolling the page – it should now scroll normally.
7. Record any failures in `storage/logs/modal_smoke_test_failures.log`.

## Success Criteria
- **All** listed modals pass the above steps.
- No console errors related to CSS or JavaScript appear in the browser dev tools.
- The overlay uses the `.custom-modal-overlay` class with `overflow-y: auto`.

---
*Generated automatically by Antigravity agent as part of the post‑fix verification process.*
