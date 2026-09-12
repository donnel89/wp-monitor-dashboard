/* מוסיף לפרויקט iOS את מה ש-Capacitor לא מוסיף לבד:
   נגינה ברקע, הרשאת מיקרופון, מצב לאורך בלבד, אייקון ומסך פתיחה.
   הסקריפט אידמפוטנטי - אפשר להריץ אותו שוב אחרי כל cap add / cap sync */
import { readFile, writeFile, copyFile, access } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const iosApp = join(root, 'ios', 'App', 'App');
const done = [];

async function exists(p) { try { await access(p); return true; } catch { return false; } }

if (!(await exists(iosApp))) {
  console.error('לא נמצאה תיקיית ios. הריצו קודם: npx cap add ios');
  process.exit(1);
}

/* ---------- Info.plist ---------- */
const plistPath = join(iosApp, 'Info.plist');
let plist = await readFile(plistPath, 'utf8');

/* נגינה ברקע: בלי זה iOS משתיק את האודיו ברגע שהמסך ננעל */
if (!plist.includes('UIBackgroundModes')) {
  plist = plist.replace('</dict>\n</plist>',
    '\t<key>UIBackgroundModes</key>\n\t<array>\n\t\t<string>audio</string>\n\t</array>\n</dict>\n</plist>');
  done.push('נגינה ברקע');
}

/* הרשאת מיקרופון להקלטה בתוך האפליקציה */
if (!plist.includes('NSMicrophoneUsageDescription')) {
  plist = plist.replace('</dict>\n</plist>',
    '\t<key>NSMicrophoneUsageDescription</key>\n' +
    '\t<string>כדי להקליט את הששש שלכם ולנגן אותו לתינוק בלולאה. ' +
    'ההקלטה נשמרת במכשיר בלבד ולא נשלחת לשום מקום.</string>\n</dict>\n</plist>');
  done.push('הרשאת מיקרופון');
}

/* לאורך בלבד - אפליקציית לילה לא צריכה סיבוב מסך */
const portraitOnly = '\t<key>UISupportedInterfaceOrientations</key>\n\t<array>\n' +
  '\t\t<string>UIInterfaceOrientationPortrait</string>\n\t</array>';
const orient = /\t<key>UISupportedInterfaceOrientations<\/key>\n\t<array>[\s\S]*?<\/array>/;
const current = plist.match(orient);
if (current && current[0].includes('Landscape')) {
  plist = plist.replace(orient, portraitOnly);
  done.push('מצב לאורך בלבד');
}

await writeFile(plistPath, plist);

/* ---------- AppDelegate.swift ---------- */
const delPath = join(iosApp, 'AppDelegate.swift');
let del = await readFile(delPath, 'utf8');

if (!del.includes('configureAudioSession')) {
  if (!del.includes('import AVFoundation'))
    del = del.replace('import Capacitor', 'import Capacitor\nimport AVFoundation');

  del = del.replace(
    /(func application\(_ application: UIApplication, didFinishLaunchingWithOptions[^\n]*\n)/,
    '$1        configureAudioSession()\n');

  /* iOS עשוי לאפס את סשן האודיו במעברי מצב, ולכן מגדירים אותו שוב */
  del = del.replace(
    /(func applicationDidBecomeActive\(_ application: UIApplication\) \{\n)/,
    '$1        configureAudioSession()\n');
  del = del.replace(
    /(func applicationWillResignActive\(_ application: UIApplication\) \{\n)/,
    '$1        configureAudioSession()\n');

  const helper = `
    /// מגדיר את האודיו כניגון מדיה, כדי שהצליל ימשיך כשהמסך נעול
    /// וכדי שיופיעו כפתורי הפעלה במסך הנעילה.
    func configureAudioSession() {
        let session = AVAudioSession.sharedInstance()
        do {
            try session.setCategory(.playback, mode: .default, options: [])
            try session.setActive(true, options: [])
        } catch {
            print("שגיאה בהגדרת סשן האודיו: \\(error)")
        }
    }
`;
  const last = del.lastIndexOf('}');
  del = del.slice(0, last) + helper + del.slice(last);
  await writeFile(delPath, del);
  done.push('סשן אודיו לנגינה ברקע');
}

/* ---------- אייקון ומסך פתיחה ---------- */
const icon = join(root, 'assets', 'icon-1024.png');
const splash = join(root, 'assets', 'splash-2732.png');
const xc = join(iosApp, 'Assets.xcassets');

if (await exists(icon)) {
  await copyFile(icon, join(xc, 'AppIcon.appiconset', 'AppIcon-512@2x.png'));
  done.push('אייקון');
}
if (await exists(splash)) {
  for (const f of ['splash-2732x2732.png', 'splash-2732x2732-1.png', 'splash-2732x2732-2.png'])
    await copyFile(splash, join(xc, 'Splash.imageset', f));
  done.push('מסך פתיחה');
}

console.log(done.length ? 'הוחל: ' + done.join(', ') : 'הכל כבר מוגדר, אין מה לעדכן');
