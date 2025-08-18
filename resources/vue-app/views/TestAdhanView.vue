<template>
    <div class="d-flex w-100 text-center py-8">
        <div class="table-responsive">
            <table class="table table-primary">
                <thead>
                    <tr>
                        <th scope="col">Fajr</th>
                        <th scope="col">Dhuhr</th>
                        <th scope="col">Asr</th>
                        <th scope="col">Maghrib</th>
                        <th scope="col">Isha</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="">
                        <td>{{ prayerTimes.fajr.getHours() }} : {{ prayerTimes.fajr.getMinutes() }} : {{ prayerTimes.fajr.getSeconds() }}</td>
                        <td>{{ prayerTimes.dhuhr }}</td>
                        <td>{{ prayerTimes.asr }}</td>
                        <td>{{ prayerTimes.maghrib }}</td>
                        <td>{{ prayerTimes.isha }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

<script setup>
import { Coordinates, CalculationMethod, PrayerTimes } from 'adhan';

const coordinates = new Coordinates(30.87605680, 29.74260400)
const params = CalculationMethod.MoonsightingCommittee()
const date = new Date()
const prayerTimes = new PrayerTimes(coordinates, date, params)

</script>