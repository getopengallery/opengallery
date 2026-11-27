---
order: 27
---

# Licenses

A license tells others what they may do with a photo, for example "CC BY 4.0" (share it, as long as you name the photographer) or "All rights reserved". Every photo can carry one license.

Licenses are off by default. An administrator turns them on, and chooses the license new photos get, under [Settings → Licenses](../administration/settings.md#licenses). While they are off, nothing about licenses is shown, but photos keep the license they have.

## The license of a new photo

While licenses are on, every uploaded photo gets the default license. Photos uploaded before keep the license they had, or stay without one. Nobody has to choose anything when uploading.

## Changing the license of a photo

Open the photo in the media library (see [A single photo](photos.md#a-single-photo)) and use the **License** field under **Where it is**. Below the field, the description of the selected license tells you in plain words what it allows, with a **Read the license** link to the full text. Click **Save changes** to store it.

![The License field of a photo with the description of the selected license](../../assets/licenses-field.png) <!-- TODO: screenshot of the License field on a photo page, with the description and "Read the license" link -->

- You can change the license of a photo you may edit (see [Who may change a photo](photos.md#who-may-change-a-photo)).
- The list only offers the licenses the administrator allows. The default license can always be chosen.
- When the administrator has turned off **Uploaders choose the license**, the field is read-only. People with the permission "Change site settings" can still pick any active license.
- A photo whose license was deactivated later still shows it, and keeps it until you choose another.

To give many photos a license at once, select them in the media library and choose **Set license** (only shown while you may choose a license; see [Working with several photos](photos.md#working-with-several-photos)). Photos you may not edit are left out, and you are told how many. To find photos by license, use the **License** filter of the media library (see [Finding photos](photos.md#finding-photos)).

## Where the license is shown

- On the photo page in the admin, also when it is read-only.
- On the site, in the **i** panel of the photo viewer: the license is shown with a link to its text. Pointing at its name shows the full name. It is only shown while licenses and [photo information](../administration/settings.md#photo-information) are both turned on, and not for photos without a license.
- On the site, below the photo in the viewer, when an administrator chose **License** for **Below the photo** (see [Photo information](../administration/settings.md#photo-information)). It is shown as a link to the license text, only while licenses are turned on (the photo information switch does not matter here), and not for photos without a license.

![The photo information panel in the viewer, showing the license](../../assets/licenses-viewer.png) <!-- TODO: screenshot of the photo viewer's information panel with a license -->

## Changes are logged

The [activity log](../administration/activity.md) records changes to a photo's license, and when licenses themselves are created, changed or deleted. Setting the license of many photos at once is logged as one entry.
