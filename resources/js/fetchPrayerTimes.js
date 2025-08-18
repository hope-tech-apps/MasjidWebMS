import { Coordinates, CalculationMethod, PrayerTimes } from 'adhan';

// Extract PHP params sent by the process node command
// with execluding the first 2 params (first 2 are default, not acctually the sent by PHP)
const args = process.argv.slice(2)

const getDataOptions = {
    latitude: args[0],
    logitude: args[1],
    from: new Date(args[2]),
    to: new Date(args[4]),
    datesArray: []
}

// Filling dates range array
let loopDate = new Date(getDataOptions.from)
while (loopDate <= getDataOptions.to) {
    getDataOptions.datesArray.push(new Date(loopDate))
    loopDate.setDate(loopDate.getDate() + 1)
}

const prayersData = []

for(let x in getDataOptions.datesArray) {
    // Fetch day prayers
    const coordinates = new Coordinates(getDataOptions.latitude, getDataOptions.logitude)
    const params = CalculationMethod.MoonsightingCommittee()
    const dayPrayers = new PrayerTimes(coordinates, getDataOptions.datesArray[x], params)
    
    // no need to push with the date since the dayPrayers contains the date
    // {prayers_data: prayerTimes, date: getDataOptions.datesArray[x]}
    prayersData.push(dayPrayers)
}

console.log(JSON.stringify(prayersData))