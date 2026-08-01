// :core — pure Kotlin/JVM. NO Android plugin, NO android {} block, NO androidx.
// This is what lets the whole logic layer test in seconds without an emulator,
// the twin of the website's `php tools/test.php` and iOS's `swift test`.
plugins {
    alias(libs.plugins.kotlin.jvm)
    alias(libs.plugins.kotlin.serialization)
}

dependencies {
    implementation(libs.kotlinx.serialization.json)
    testImplementation(libs.junit)
}

kotlin {
    jvmToolchain(17)
}

tasks.test {
    useJUnit()
}
