import { readFileSync, writeFileSync, existsSync } from 'node:fs'
import { resolve } from 'node:path'

const agpVersion = '8.12.1'
const files = [
  'android/build.gradle',
  'android/capacitor-cordova-android-plugins/build.gradle',
  'node_modules/@capacitor/android/capacitor/build.gradle',
]

for (const file of files) {
  const path = resolve(file)
  if (!existsSync(path)) continue

  const source = readFileSync(path, 'utf8')
  const updated = source.replace(/com\.android\.tools\.build:gradle:\d+\.\d+\.\d+/g, `com.android.tools.build:gradle:${agpVersion}`)
  if (updated !== source) writeFileSync(path, updated)
}
