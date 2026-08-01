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

// Target Java 17 bytecode WITHOUT pinning a toolchain. A toolchain (jvmToolchain(17))
// forces Gradle to locate a JDK 17 install and fails when the IDE runs Gradle on a
// different JVM (Android Studio's bundled JBR) with no JDK 17 discoverable. This just
// compiles to 17 on whatever JDK 17+ runs the build — the same setup as :app.
java {
    sourceCompatibility = JavaVersion.VERSION_17
    targetCompatibility = JavaVersion.VERSION_17
}
kotlin {
    compilerOptions {
        jvmTarget.set(org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17)
    }
}

tasks.test {
    useJUnit()
}
